<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\ClientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private function begin(string $provider): array
    {
        config(['sendae.login_providers.'.$provider => ['client_id' => 'login-client', 'client_secret' => 'login-secret']]);
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $start = $this->postJson('/api/social-login/'.$provider, ['challenge' => $challenge])->assertOk()->json();
        $response = $this->get($start['url'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('login-client', $parameters['client_id']);
        $this->assertSame(route('social-login.callback', $provider), $parameters['redirect_uri']);

        return [$start['ticket'], $verifier, $parameters];
    }

    private function tokenClient(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test desktop', 'users');
    }

    public static function providers(): array
    {
        return [
            'google' => ['google', 'https://oauth2.googleapis.com/token', 'https://openidconnect.googleapis.com/v1/userinfo', ['sub' => 'person-123', 'name' => 'Person', 'email' => 'person@example.com']],
            'facebook' => ['facebook', 'https://graph.facebook.com/v24.0/oauth/access_token', 'https://graph.facebook.com/v24.0/me*', ['id' => 'person-123', 'name' => 'Person', 'email' => 'person@example.com']],
            'x without email' => ['x', 'https://api.x.com/2/oauth2/token', 'https://api.x.com/2/users/me', ['data' => ['id' => 'person-123', 'name' => 'Person']]],
        ];
    }

    #[DataProvider('providers')]
    public function test_creates_account_and_reuses_provider_identity(string $provider, string $tokenUrl, string $profileUrl, array $profile): void
    {
        Http::preventStrayRequests();
        Http::fake([$tokenUrl => Http::response(['access_token' => 'provider-secret']), $profileUrl => Http::response($profile)]);
        $this->tokenClient();
        [$ticket, $verifier, $parameters] = $this->begin($provider);
        $this->assertStringNotContainsString('write', $parameters['scope']);
        if ($provider !== 'facebook') {
            $this->assertSame('S256', $parameters['code_challenge_method']);
        }
        $callback = '/sign-in/'.$provider.'/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'provider-code']);
        $this->get($callback)->assertRedirect('sendae://sign-in?ticket='.$ticket)->assertContent('')->assertDontSee('provider-secret');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === $tokenUrl && $request['redirect_uri'] === route('social-login.callback', $provider) && ($provider === 'facebook' || ! empty($request['code_verifier'])));
        $finish = '/api/social-login/finish/'.$ticket;
        $this->postJson($finish, ['verifier' => $verifier])->assertOk()->assertJsonPath('needs_profile', true)->assertJsonPath('name', 'Person')->assertJsonMissingPath('token');
        $this->assertDatabaseCount('users', 0);
        $session = $this->postJson($finish, ['verifier' => $verifier, 'name' => 'Person', 'email' => 'PERSON@example.com'])->assertOk()->json();
        $user = User::firstOrFail();
        $this->assertSame('person@example.com', $user->email);
        $this->assertSame($user->workspace_id, $session['workspace_id']);
        $this->assertDatabaseHas('login_identities', ['user_id' => $user->id, 'provider' => $provider, 'provider_id' => 'person-123']);
        $this->withToken($session['token'])->getJson('/api/workspaces')->assertOk();
        $this->postJson($finish, ['verifier' => $verifier])->assertGone();
        $this->get($callback)->assertForbidden();

        $this->travel(61)->seconds();
        [$ticket, $verifier, $parameters] = $this->begin($provider);
        $this->get('/sign-in/'.$provider.'/callback?'.http_build_query(['state' => $parameters['state'], 'code' => 'second-code']))->assertRedirect();
        $this->postJson('/api/social-login/finish/'.$ticket, ['verifier' => $verifier])->assertOk()->assertJsonPath('workspace_id', $user->workspace_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('login_identities', 1);
    }

    public function test_requires_password_before_linking_an_existing_email(): void
    {
        $this->tokenClient();
        $user = User::factory()->create(['email' => 'person@example.com']);
        $ticket = str_repeat('t', 64);
        $verifier = str_repeat('v', 64);
        Cache::put('login_result:'.$ticket, ['challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'provider' => 'google', 'provider_id' => '123', 'name' => 'Person', 'email' => $user->email], now()->addMinutes(10));
        $payload = ['verifier' => $verifier, 'name' => 'Changed name', 'email' => $user->email];
        $url = '/api/social-login/finish/'.$ticket;

        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors(['password' => 'Enter your existing Sendae password to link this sign-in.']);
        $this->postJson($url, $payload + ['password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseCount('login_identities', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->postJson($url, $payload + ['password' => 'password'])->assertOk()->assertJsonPath('workspace_id', $user->workspace_id);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('login_identities', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_rejects_foreign_device_state_and_expired_handoffs(): void
    {
        Http::preventStrayRequests();
        [$ticket, $verifier, $parameters] = $this->begin('google');
        $this->get('/sign-in/x/callback?state='.$parameters['state'].'&code=code')->assertForbidden();
        $this->get('/sign-in/google/callback?state='.str_repeat('z', 64).'&code=code')->assertForbidden();
        $this->get('/sign-in/'.$ticket)->assertGone();
        Cache::put('login_result:'.$ticket, ['challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'provider' => 'google'], now()->addMinutes(10));
        $this->postJson('/api/social-login/finish/'.$ticket, ['verifier' => str_repeat('w', 64)])->assertForbidden();
        $this->travel(11)->minutes();
        $this->postJson('/api/social-login/finish/'.$ticket, ['verifier' => $verifier])->assertGone();
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_cancelled_login_returns_an_actionable_error_without_creating_an_account(): void
    {
        Http::preventStrayRequests();
        [$ticket, $verifier, $parameters] = $this->begin('facebook');
        $this->get('/sign-in/facebook/callback?state='.$parameters['state'].'&error=access_denied')->assertRedirect('sendae://sign-in?ticket='.$ticket);
        $this->postJson('/api/social-login/finish/'.$ticket, ['verifier' => $verifier])->assertUnprocessable()->assertJsonPath('message', 'Sign-in was cancelled. Try again.');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_provider_failure_does_not_expose_provider_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'login-secret'], 400)]);
        [$ticket, $verifier, $parameters] = $this->begin('google');
        $this->get('/sign-in/google/callback?state='.$parameters['state'].'&code=code')->assertRedirect();
        $this->postJson('/api/social-login/finish/'.$ticket, ['verifier' => $verifier])->assertUnprocessable()->assertJsonPath('message', 'The provider could not complete sign-in. Try again.')->assertDontSee('login-secret');
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_and_unknown_providers_cannot_start_login(): void
    {
        config(['sendae.login_providers.google' => ['client_id' => null, 'client_secret' => null]]);
        $this->postJson('/api/social-login/google', ['challenge' => str_repeat('c', 43)])->assertServiceUnavailable();
        $this->postJson('/api/social-login/unknown', ['challenge' => str_repeat('c', 43)])->assertNotFound();
        $this->postJson('/api/social-login/google', ['challenge' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }
}
