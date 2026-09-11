<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_account_can_sign_in_access_workspace_and_revoke_its_token(): void
    {
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
    }

    public function test_invalid_password_and_different_workspace_issue_no_tokens(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/session', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/session', ['email' => $user->email, 'password' => 'password', 'workspace_id' => str_repeat('x', 64)])->assertStatus(409);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_login_is_validated_and_rate_limited(): void
    {
        $this->postJson('/api/session', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/session', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/session', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertTooManyRequests();
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_each_customer_has_their_own_workspace(): void
    {
        $first = User::factory()->create();
        $other = User::factory()->create();
        $this->assertNotSame($first->workspace_id, $other->workspace_id);
        Passport::actingAs($other, ['mcp:use']);
        $this->getJson('/api/state')->assertOk()->assertJsonCount(0, 'drafts');
        $this->postJson('/local/settings', [])->assertNotFound();
    }
}
