<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Draft;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublicationLinksTest extends TestCase
{
    use RefreshDatabase;

    private function publication(string $provider = 'threads', array $receipts = ['123', '456'], string $status = 'connected'): Publication
    {
        $account = Account::create(['provider' => $provider, 'provider_id' => 'author', 'name' => 'Author', 'status' => $status, 'credentials' => ['access_token' => 'secret']]);
        $draft = Draft::create(['title' => 'Thread', 'content' => ['items' => [], 'overrides' => [], 'account_ids' => []]]);

        return Publication::create(['account_id' => $account->id, 'draft_id' => $draft->id, 'status' => 'published', 'scheduled_at' => now(), 'snapshot' => ['title' => 'Thread', 'items' => [['text' => 'First'], ['text' => 'Reply']]], 'receipts' => $receipts]);
    }

    public function test_state_includes_cached_links_for_each_receipt_in_the_current_workspace_only(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.threads.net/v1.0/me*' => Http::response([]),
            'graph.threads.net/v1.0/123*' => Http::response(['permalink' => 'https://www.threads.com/@author/post/ABC']),
            'graph.threads.net/v1.0/456*' => Http::response(['permalink' => 'https://www.threads.net/@author/post/DEF']),
        ]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->publication(receipts: ['other-owner-post']);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $publication = $this->publication();

        $this->getJson('/api/state')->assertOk()->assertJsonCount(1, 'publications')
            ->assertJsonPath('publications.0.snapshot.post_urls.123', 'https://www.threads.com/@author/post/ABC')
            ->assertJsonPath('publications.0.snapshot.post_urls.456', 'https://www.threads.net/@author/post/DEF')
            ->assertJsonPath('publications.0.receipts', ['123', '456'])
            ->assertJsonPath('publications.0.snapshot.items', $publication->snapshot['items'])
            ->assertDontSee('secret')->assertDontSee('other-owner-post');
        $this->getJson('/api/state')->assertOk();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/v1.0/123?fields=permalink' && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    #[TestWith([null, 200])]
    #[TestWith(['javascript:alert(1)', 200])]
    #[TestWith(['https://evil.example/post/ABC', 200])]
    #[TestWith(['https://user:password@www.threads.com/post/ABC', 200])]
    #[TestWith(['https://www.threads.com/@author/post/ABC', 403])]
    public function test_missing_invalid_or_failed_links_do_not_block_state(?string $url, int $status): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.threads.net/v1.0/me*' => Http::response([]),
            'graph.threads.net/v1.0/123*' => Http::response(['permalink' => $url], $status),
        ]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->publication(receipts: ['123']);

        $this->getJson('/api/state')->assertOk()->assertJsonPath('publications.0.snapshot.post_urls.123', null);
        $this->getJson('/api/state')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_connection_failure_does_not_block_state_or_repeat_on_every_sync(): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.threads.net/*' => Http::failedConnection()]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->publication(receipts: ['123']);

        $this->getJson('/api/state')->assertOk()->assertJsonPath('publications.0.snapshot.post_urls.123', null);
        Http::fake(['graph.threads.net/*' => Http::response(['permalink' => 'https://www.threads.com/@author/post/ABC'])]);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('publications.0.snapshot.post_urls.123', null);
        Http::assertNothingSent();
    }

    public function test_other_networks_and_disconnected_accounts_do_not_request_links(): void
    {
        Http::preventStrayRequests();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->publication(provider: 'x');
        $this->publication(status: 'disconnected');
        Http::fake(['https://api.x.com/2/users/me?user.fields=profile_image_url' => Http::response(['data' => []])]);

        $this->getJson('/api/state')->assertOk()->assertJsonCount(2, 'publications');
        Http::assertSentCount(1);
    }
}
