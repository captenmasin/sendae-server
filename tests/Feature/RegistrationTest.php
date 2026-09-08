<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function tokens(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test desktop', 'users');
    }

    public function test_public_registration_allows_immediate_sign_in_without_email_verification(): void
    {
        $this->tokens();
        Notification::fake();
        $input = ['name' => 'First customer', 'email' => 'CUSTOMER@example.com', 'password' => '12345678', 'password_confirmation' => '12345678'];
        $this->postJson('/api/register', array_replace($input, ['password' => '1234567', 'password_confirmation' => '1234567']))->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The password field must be at least 8 characters.']);
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/register', $input)->assertCreated()->assertJsonMissingPath('token');
        $user = User::firstOrFail();
        $this->assertSame('customer@example.com', $user->email);
        $this->assertTrue(Hash::check($input['password'], $user->password));
        Notification::assertNothingSent();
        $this->assertNull($user->email_verified_at);
        $this->postJson('/api/session', $input)->assertOk()->assertJsonPath('workspace_id', $user->workspace_id);
        $this->postJson('/api/register', $input)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_signup_is_rejected_and_verification_endpoints_are_removed(): void
    {
        Notification::fake();
        $this->postJson('/api/register', ['name' => 'Test', 'email' => 'bad', 'password' => 'short', 'password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 0);
        $user = User::factory()->unverified()->create();
        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        $this->postJson('/api/verification', ['email' => $user->email, 'password' => 'password'])->assertNotFound();
        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_reset_link_is_private_single_use_and_revokes_existing_sessions(): void
    {
        $this->tokens();
        Notification::fake();
        $user = User::factory()->create();
        $user->createToken('Old desktop', ['mcp:use']);
        $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->json();
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])->assertOk()->assertExactJson($known);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $data = ['email' => $user->email, 'token' => $token, 'password' => '87654321', 'password_confirmation' => '87654321'];
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertRedirect('sendae://reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]))->assertContent('');
        $this->postJson('/api/reset-password', array_replace($data, ['password' => '1234567', 'password_confirmation' => '1234567']))->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The password field must be at least 8 characters.']);
        $this->assertSame($user->password, $user->fresh()->password);
        $this->postJson('/api/reset-password', $data)->assertOk()->assertJsonPath('message', 'Password updated. Sign in with your new password.');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => true]);
        $this->postJson('/api/reset-password', $data)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/register', [])->assertTooManyRequests();
    }

    public function test_production_signup_does_not_require_mail_delivery(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);
        Notification::fake();
        $this->postJson('/api/register', ['name' => 'Test', 'email' => 'test@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertCreated()->assertJsonPath('message', 'Account created. Sign in to Sendae to continue.');
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'email_verified_at' => null]);
        Notification::assertNothingSent();
    }
}
