<?php

use App\Models\User;
use App\Models\Account;
use Laravel\Passport\Passport;
use App\Services\ProviderFailure;
use App\Services\SocialProviders;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['sendae.providers.x.client_id' => 'client', 'sendae.providers.x.client_secret' => 'secret']);
    Http::preventStrayRequests();
});

function xAuthorizationAccount(): Account
{
    return Account::create(['provider' => 'x', 'provider_id' => '123', 'name' => 'Mason', 'status' => 'connected', 'credentials' => [
        'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'expires_at' => now()->addHours(2)->toIso8601String(),
    ]]);
}

test('healthy two hour X tokens are reused without refreshing', function (): void {
    $this->actingAs(User::factory()->create());
    $account = xAuthorizationAccount();
    Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => 'post']])]);

    app(SocialProviders::class)->send($account, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Hello'], true);
    app(SocialProviders::class)->send($account, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Another'], true);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer old-access'));
});

test('refresh rotation is saved and stale account instances reuse the newest token', function (): void {
    $this->freezeTime();
    $this->actingAs(User::factory()->create());
    $account = xAuthorizationAccount();
    $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->addMinutes(4)->toIso8601String()]]);
    $staleAccount = Account::findOrFail($account->id);
    Http::fake([
        'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 7200]),
        'api.x.com/2/tweets' => Http::response(['data' => ['id' => 'post']]),
    ]);

    app(SocialProviders::class)->send($account, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Hello'], true);
    app(SocialProviders::class)->send($staleAccount, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Another'], true);

    expect($account->fresh()->credentials)->toBe(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_at' => now()->addHours(2)->toIso8601String()]);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/oauth2/token' && $request['refresh_token'] === 'old-refresh' && $request['grant_type'] === 'refresh_token' && $request->hasHeader('Authorization', 'Basic '.base64_encode('client:secret')));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/tweets') && ! $request->hasHeader('Authorization', 'Bearer new-access'));
});

test('state refreshes X authorization while profile details remain cached and keeps credentials private', function (): void {
    $this->freezeTime();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $other = xAuthorizationAccount();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $account = xAuthorizationAccount();
    Http::fake([
        'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 7200]),
        'api.x.com/2/users/me*' => Http::response(['data' => ['profile_image_url' => 'https://images.example/x.png']]),
    ]);
    $this->getJson('/api/state')->assertOk();
    $this->travel(116)->minutes();

    $this->getJson('/api/state')->assertOk()->assertJsonCount(1, 'accounts')->assertJsonPath('accounts.0.status', 'connected')
        ->assertDontSee('old-access')->assertDontSee('new-access')->assertDontSee('old-refresh');

    expect($account->fresh()->credentials['refresh_token'])->toBe('old-refresh');
    expect($other->fresh()->credentials['access_token'])->toBe('old-access');
    Http::assertSentCount(3);
});

test('refresh failures never publish or discard credentials and only a rejected grant expires the account', function (int $status, array $body, string $accountStatus, string $outcome): void {
    $this->actingAs(User::factory()->create());
    $account = xAuthorizationAccount();
    $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->subMinute()->toIso8601String()]]);
    $credentials = $account->credentials;
    Http::fake(['api.x.com/2/oauth2/token' => Http::response($body, $status)]);

    try {
        app(SocialProviders::class)->send($account, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Hello'], true);
        $this->fail('Publishing must stop when refreshing fails.');
    } catch (ProviderFailure $failure) {
        expect($failure->outcome)->toBe($outcome);
    }

    expect($account->fresh()->status)->toBe($accountStatus);
    expect($account->fresh()->credentials)->toBe($credentials);
    Http::assertSentCount(1);
})->with([
    'revoked grant' => [400, ['error' => 'invalid_grant'], 'expired', 'failed'],
    'client configuration failure' => [401, ['error' => 'invalid_client'], 'connected', 'retry'],
    'rate limit' => [429, [], 'connected', 'retry'],
    'provider outage' => [503, [], 'connected', 'retry'],
    'incomplete response' => [200, ['access_token' => 'new'], 'connected', 'retry'],
]);

test('refresh network failures are retryable before sending a post', function (): void {
    $this->actingAs(User::factory()->create());
    $account = xAuthorizationAccount();
    $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->subMinute()->toIso8601String()]]);
    Http::fake(['api.x.com/2/oauth2/token' => Http::failedConnection()]);

    try {
        app(SocialProviders::class)->send($account, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Hello'], true);
        $this->fail('Publishing must stop when refreshing fails.');
    } catch (ProviderFailure $failure) {
        expect($failure->outcome)->toBe('retry');
    }

    expect($account->fresh()->status)->toBe('connected');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/tweets'));
});

test('state reports rejected X grants as expired so the desktop can notify', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $account = xAuthorizationAccount();
    $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->subMinute()->toIso8601String()]]);
    Http::fake(['api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.status', 'expired');
    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.status', 'expired');

    Http::assertSentCount(1);
});

test('state reports revoked X access tokens as expired without blocking sync', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    xAuthorizationAccount();
    Http::fake(['api.x.com/2/users/me*' => Http::response([], 401)]);

    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.status', 'expired');

    Http::assertSentCount(1);
});

test('expired X accounts without a refresh token request reconnection before publishing', function (): void {
    $this->actingAs(User::factory()->create());
    $account = xAuthorizationAccount();
    $account->update(['credentials' => ['access_token' => 'old-access', 'expires_at' => now()->subMinute()->toIso8601String()]]);

    expect(fn () => app(SocialProviders::class)->send($account, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Hello'], true))->toThrow(ProviderFailure::class, 'Reconnect');

    expect($account->fresh()->status)->toBe('expired');
    Http::assertNothingSent();
});

test('X unauthorized responses expire accounts while permission failures keep the connection', function (int $status, string $accountStatus): void {
    $this->actingAs(User::factory()->create());
    $account = xAuthorizationAccount();
    Http::fake(['api.x.com/2/tweets' => Http::response([], $status)]);

    expect(fn () => app(SocialProviders::class)->send($account, 'POST', 'https://api.x.com/2/tweets', ['text' => 'Hello'], true))->toThrow(ProviderFailure::class);

    expect($account->fresh()->status)->toBe($accountStatus);
    Http::assertSentCount(1);
})->with([[401, 'expired'], [403, 'connected']]);
