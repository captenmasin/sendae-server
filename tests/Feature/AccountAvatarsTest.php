<?php

use App\Models\User;
use App\Models\Account;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Http;

test('state returns cached pictures only for the current owner', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'graph.threads.net/*' => Http::response(['threads_profile_picture_url' => 'https://images.example/threads.jpg']),
        'graph.facebook.com/*' => Http::response(['picture' => ['data' => ['url' => 'https://images.example/page.jpg']]]),
    ]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Account::create(['provider' => 'threads', 'provider_id' => 'other', 'name' => 'Other owner', 'credentials' => ['access_token' => 'other-secret']]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Account::create(['provider' => 'facebook', 'provider_id' => 'page', 'name' => 'Facebook Page', 'credentials' => ['access_token' => 'page-secret']]);
    Account::create(['provider' => 'threads', 'provider_id' => 'threads', 'name' => 'Threads user', 'credentials' => ['access_token' => 'threads-secret']]);

    $this->getJson('/api/state')->assertOk()->assertJsonCount(2, 'accounts')
        ->assertJsonPath('accounts.0.avatar_url', 'https://images.example/page.jpg')
        ->assertJsonPath('accounts.1.avatar_url', 'https://images.example/threads.jpg')
        ->assertDontSee('page-secret')->assertDontSee('threads-secret')->assertDontSee('Other owner');
    $this->getJson('/api/state')->assertOk();
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer other-secret'));
    $this->travel(61)->minutes();
    $this->getJson('/api/state')->assertOk();
    Http::assertSentCount(4);
});

test('state returns and caches linkedin and x pictures', function (string $provider, string $endpoint, array $body, ?string $picture, int $status = 200): void {
    Http::preventStrayRequests();
    Http::fake([$endpoint => Http::response($body, $status)]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Account::create(['provider' => $provider, 'provider_id' => 'profile', 'name' => 'Profile', 'credentials' => ['access_token' => 'profile-secret']]);

    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', $picture)->assertDontSee('profile-secret');
    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', $picture);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer profile-secret'));
})->with([
    ['linkedin', 'https://api.linkedin.com/v2/userinfo', ['picture' => 'https://images.example/linkedin.jpg'], 'https://images.example/linkedin.jpg'],
    ['x', 'https://api.x.com/2/users/me*', ['data' => ['profile_image_url' => 'https://images.example/x.jpg']], 'https://images.example/x.jpg'],
    ['linkedin', 'https://api.linkedin.com/v2/userinfo', [], null],
    ['x', 'https://api.x.com/2/users/me*', ['data' => []], null],
    ['linkedin', 'https://api.linkedin.com/v2/userinfo', ['picture' => 'https://images.example/linkedin.jpg'], null, 403],
    ['x', 'https://api.x.com/2/users/me*', ['data' => ['profile_image_url' => 'https://images.example/x.jpg']], null, 429],
]);

test('avatar connection failure does not block state or retry on every sync', function (): void {
    Http::preventStrayRequests();
    Http::fake(['graph.threads.net/*' => Http::failedConnection()]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Account::create(['provider' => 'threads', 'provider_id' => 'threads', 'name' => 'Threads user', 'credentials' => ['access_token' => 'secret']]);
    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null);
    Http::fake(['graph.threads.net/*' => Http::response(['threads_profile_picture_url' => 'https://images.example/new.jpg'])]);
    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null);
    Http::assertNothingSent();
});

test('invalid or failed provider picture responses use the fallback', function (?string $url, int $status): void {
    Http::preventStrayRequests();
    Http::fake(['graph.threads.net/*' => Http::response(['threads_profile_picture_url' => $url], $status)]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Account::create(['provider' => 'threads', 'provider_id' => 'threads', 'name' => 'Threads user', 'credentials' => ['access_token' => 'secret']]);
    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null);
    Http::assertSentCount(1);
})->with([
    ['http://images.example/avatar.jpg', 200],
    ['https://user:password@images.example/avatar.jpg', 200],
    [null, 200],
    ['https://images.example/avatar.jpg', 403],
]);

test('disconnected and unsupported accounts do not request pictures', function (): void {
    Http::preventStrayRequests();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Account::create(['provider' => 'threads', 'provider_id' => 'threads', 'name' => 'Disconnected', 'status' => 'disconnected']);
    Account::create(['provider' => 'linkedin_page', 'provider_id' => 'urn:li:organization:123', 'name' => 'Page', 'credentials' => ['access_token' => 'page-secret']]);
    $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null)->assertJsonPath('accounts.1.avatar_url', null);
    Http::assertNothingSent();
});
