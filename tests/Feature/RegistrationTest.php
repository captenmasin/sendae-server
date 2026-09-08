<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
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

    public function test_public_registration_requires_verification_before_issuing_a_session(): void
    {
        $this->tokens();
        Notification::fake();
        $input = ['name' => 'First customer', 'email' => 'CUSTOMER@example.com', 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password'];
        $this->postJson('/api/register', $input)->assertCreated()->assertJsonMissingPath('token');
        $user = User::firstOrFail();
        $this->assertSame('customer@example.com', $user->email);
        $this->assertTrue(Hash::check($input['password'], $user->password));
        $this->postJson('/api/session', $input)->assertForbidden();
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });
        $this->get($url)->assertRedirect('sendae://verified')->assertContent('');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->postJson('/api/session', $input)->assertOk()->assertJsonPath('workspace_id', $user->workspace_id);
        $this->postJson('/api/register', $input)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_signup_and_forged_or_expired_verification_links_fail(): void
    {
        Notification::fake();
        $this->postJson('/api/register', ['name' => 'Test', 'email' => 'bad', 'password' => 'short', 'password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 0);
        $user = User::factory()->unverified()->create();
        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertForbidden();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($expired)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
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
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'];
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertRedirect('sendae://reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]))->assertContent('');
        $this->postJson('/api/reset-password', $data)->assertOk()->assertJsonPath('message', 'Password updated. Sign in with your new password.');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => true]);
        $this->postJson('/api/reset-password', $data)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_and_verification_resend_are_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $credentials = ['email' => $user->email, 'password' => 'password'];
        $this->postJson('/api/verification', $credentials)->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->postJson('/api/verification', $credentials)->assertOk();
        $this->postJson('/api/verification', $credentials)->assertOk();
        $this->postJson('/api/verification', $credentials)->assertTooManyRequests();
        // A different IP keeps the registration limit independent of resend attempts.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/register', [])->assertTooManyRequests();
    }

    public function test_production_signup_cannot_claim_to_send_email_to_a_log(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);
        $this->postJson('/api/register', ['name' => 'Test', 'email' => 'test@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
    }
}
