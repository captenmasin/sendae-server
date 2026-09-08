<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountAvatarsTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_returns_cached_pictures_only_for_the_current_owner(): void
    {
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
    }

    public function test_avatar_connection_failure_does_not_block_state_or_retry_on_every_sync(): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.threads.net/*' => Http::failedConnection()]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Account::create(['provider' => 'threads', 'provider_id' => 'threads', 'name' => 'Threads user', 'credentials' => ['access_token' => 'secret']]);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null);
        Http::fake(['graph.threads.net/*' => Http::response(['threads_profile_picture_url' => 'https://images.example/new.jpg'])]);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null);
        Http::assertNothingSent();
    }

    #[TestWith(['http://images.example/avatar.jpg', 200])]
    #[TestWith(['https://user:password@images.example/avatar.jpg', 200])]
    #[TestWith([null, 200])]
    #[TestWith(['https://images.example/avatar.jpg', 403])]
    public function test_invalid_or_failed_provider_picture_responses_use_the_fallback(?string $url, int $status): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.threads.net/*' => Http::response(['threads_profile_picture_url' => $url], $status)]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Account::create(['provider' => 'threads', 'provider_id' => 'threads', 'name' => 'Threads user', 'credentials' => ['access_token' => 'secret']]);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null);
        Http::assertSentCount(1);
    }

    public function test_disconnected_and_unsupported_accounts_do_not_request_pictures(): void
    {
        Http::preventStrayRequests();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Account::create(['provider' => 'threads', 'provider_id' => 'threads', 'name' => 'Disconnected', 'status' => 'disconnected']);
        Account::create(['provider' => 'x', 'provider_id' => 'x', 'name' => 'X']);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('accounts.0.avatar_url', null)->assertJsonPath('accounts.1.avatar_url', null);
        Http::assertNothingSent();
    }
}
