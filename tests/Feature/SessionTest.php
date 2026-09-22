<?php

use App\Models\User;
use Laravel\Passport\Passport;
use Laravel\Passport\ClientRepository;

test('unverified account can sign in access workspace and revoke its token', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
    app(ClientRepository::class)->createPersonalAccessGrantClient('Test desktop', 'users');
    $user = User::factory()->unverified()->create();
    $response = $this->postJson('/api/session', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('email', $user->email)->assertJsonPath('name', $user->name);
    $this->assertSame($user->workspace_id, $response->json('workspace_id'));
    $token = $response->json('token');
    $this->withToken($token)->getJson('/api/state')->assertOk();
    $this->deleteJson('/api/session')->assertOk();
    $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => true]);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/state')->assertUnauthorized();
});

test('invalid password and different workspace issue no tokens', function (): void {
    $user = User::factory()->create();
    $this->postJson('/api/session', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
    $this->postJson('/api/session', ['email' => $user->email, 'password' => 'password', 'workspace_id' => str_repeat('x', 64)])->assertStatus(409);
    $this->assertDatabaseCount('oauth_access_tokens', 0);
});

test('login is validated and rate limited', function (): void {
    $this->postJson('/api/session', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/session', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertUnauthorized();
    }
    $this->postJson('/api/session', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertTooManyRequests();
    $this->assertDatabaseCount('oauth_access_tokens', 0);
});

test('each customer has their own workspace', function (): void {
    $first = User::factory()->create();
    $other = User::factory()->create();
    $this->assertNotSame($first->workspace_id, $other->workspace_id);
    Passport::actingAs($other, ['mcp:use']);
    $this->getJson('/api/state')->assertOk()->assertJsonCount(0, 'drafts');
    $this->postJson('/local/settings', [])->assertNotFound();
});
