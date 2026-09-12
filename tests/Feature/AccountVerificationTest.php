<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_marks_verified_social_accounts_from_the_provider_profile(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.x.com/2/users/me*' => Http::response(['data' => ['profile_image_url' => 'https://images.example/x.jpg', 'verified' => true]]),
            'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response(['avatar' => 'https://cdn.bsky.app/avatar.jpg', 'verification' => ['verifiedStatus' => 'valid']]),
            'https://api.linkedin.com/v2/userinfo' => Http::response(['picture' => 'https://images.example/linkedin.jpg']),
        ]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Account::create(['provider' => 'x', 'provider_id' => '1', 'name' => 'X user', 'credentials' => ['access_token' => 'x-secret']]);
        Account::create(['provider' => 'bluesky', 'provider_id' => 'sendae.bsky.social', 'name' => 'Bluesky user']);
        Account::create(['provider' => 'linkedin', 'provider_id' => 'li', 'name' => 'LinkedIn user', 'credentials' => ['access_token' => 'li-secret']]);

        $this->getJson('/api/state')->assertOk()
            ->assertJsonPath('accounts.0.verified', true)
            ->assertJsonPath('accounts.0.name', 'Bluesky user')
            ->assertJsonPath('accounts.1.verified', false)
            ->assertJsonPath('accounts.1.name', 'LinkedIn user')
            ->assertJsonPath('accounts.2.verified', true)
            ->assertJsonPath('accounts.2.name', 'X user');

        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.verified', true);
        Http::assertSentCount(3);
    }

    public function test_disconnected_accounts_are_not_marked_verified(): void
    {
        Http::preventStrayRequests();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Account::create(['provider' => 'x', 'provider_id' => '1', 'name' => 'X user', 'status' => 'disconnected', 'credentials' => ['access_token' => 'secret']]);

        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.verified', false);
        Http::assertNothingSent();
    }

    #[TestWith(['blue', true])]
    #[TestWith(['business', true])]
    #[TestWith(['government', true])]
    #[TestWith(['none', false])]
    #[TestWith(['unknown', false])]
    #[TestWith([null, false])]
    public function test_state_recognizes_current_x_badges_when_legacy_verification_is_false(?string $type, bool $verified): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.x.com/2/users/me*' => Http::response(['data' => ['verified' => false, 'verified_type' => $type]]),
        ]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Account::create(['provider' => 'x', 'provider_id' => '1', 'name' => 'X user', 'credentials' => ['access_token' => 'secret']]);

        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.verified', $verified);

        Http::assertSent(fn ($request) => in_array('verified_type', explode(',', $request['user.fields']), true));
    }

    #[TestWith(['threads', 'https://graph.threads.net/v1.0/me*', ['is_verified' => true], true])]
    #[TestWith(['threads', 'https://graph.threads.net/v1.0/me*', ['is_verified' => false], false])]
    #[TestWith(['facebook', 'https://graph.facebook.com/*', ['verification_status' => 'blue_verified'], true])]
    #[TestWith(['facebook', 'https://graph.facebook.com/*', ['verification_status' => 'not_verified'], false])]
    #[TestWith(['x', 'https://api.x.com/2/users/me*', ['data' => ['verified' => true, 'verified_type' => 'blue']], false, 403])]
    public function test_state_uses_successful_provider_verification_responses(string $provider, string $endpoint, array $profile, bool $verified, int $status = 200): void
    {
        Http::preventStrayRequests();
        Http::fake([$endpoint => Http::response($profile, $status)]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Account::create(['provider' => $provider, 'provider_id' => '1', 'name' => 'Profile', 'credentials' => ['access_token' => 'secret']]);

        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.verified', $verified);

        Http::assertSentCount(1);
    }
}
