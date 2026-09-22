<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Account;
use App\Models\Publication;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Http;

function publicationLinksPublication(string $provider = 'threads', array $receipts = ['123', '456'], string $status = 'connected'): Publication
{
    $account = Account::create(['provider' => $provider, 'provider_id' => 'author', 'name' => 'Author', 'status' => $status, 'credentials' => ['access_token' => 'secret']]);
    $draft = Draft::create(['title' => 'Thread', 'content' => ['items' => [], 'overrides' => [], 'account_ids' => []]]);

    return Publication::create(['account_id' => $account->id, 'draft_id' => $draft->id, 'status' => 'published', 'scheduled_at' => now(), 'snapshot' => ['title' => 'Thread', 'items' => [['text' => 'First'], ['text' => 'Reply']]], 'receipts' => $receipts]);
}

test('state includes cached links for each receipt in the current workspace only', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'graph.threads.net/v1.0/me*' => Http::response([]),
        'graph.threads.net/v1.0/123*' => Http::response(['permalink' => 'https://www.threads.com/@author/post/ABC']),
        'graph.threads.net/v1.0/456*' => Http::response(['permalink' => 'https://www.threads.net/@author/post/DEF']),
    ]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    publicationLinksPublication(receipts: ['other-owner-post']);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $publication = publicationLinksPublication();

    $this->getJson('/api/state')->assertOk()->assertJsonCount(1, 'publications')
        ->assertJsonPath('publications.0.snapshot.post_urls.123', 'https://www.threads.com/@author/post/ABC')
        ->assertJsonPath('publications.0.snapshot.post_urls.456', 'https://www.threads.net/@author/post/DEF')
        ->assertJsonPath('publications.0.receipts', ['123', '456'])
        ->assertJsonPath('publications.0.snapshot.items', $publication->snapshot['items'])
        ->assertDontSee('secret')->assertDontSee('other-owner-post');
    $this->getJson('/api/state')->assertOk();
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/v1.0/123?fields=permalink' && $request->hasHeader('Authorization', 'Bearer secret'));
});

test('missing invalid or failed links do not block state', function (?string $url, int $status): void {
    Http::preventStrayRequests();
    Http::fake([
        'graph.threads.net/v1.0/me*' => Http::response([]),
        'graph.threads.net/v1.0/123*' => Http::response(['permalink' => $url], $status),
    ]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    publicationLinksPublication(receipts: ['123']);

    $this->getJson('/api/state')->assertOk()->assertJsonPath('publications.0.snapshot.post_urls.123', null);
    $this->getJson('/api/state')->assertOk();
    Http::assertSentCount(2);
})->with([
    [null, 200],
    ['javascript:alert(1)', 200],
    ['https://evil.example/post/ABC', 200],
    ['https://user:password@www.threads.com/post/ABC', 200],
    ['https://www.threads.com/@author/post/ABC', 403],
]);

test('connection failure does not block state or repeat on every sync', function (): void {
    Http::preventStrayRequests();
    Http::fake(['graph.threads.net/*' => Http::failedConnection()]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    publicationLinksPublication(receipts: ['123']);

    $this->getJson('/api/state')->assertOk()->assertJsonPath('publications.0.snapshot.post_urls.123', null);
    Http::fake(['graph.threads.net/*' => Http::response(['permalink' => 'https://www.threads.com/@author/post/ABC'])]);
    $this->getJson('/api/state')->assertOk()->assertJsonPath('publications.0.snapshot.post_urls.123', null);
    Http::assertNothingSent();
});

test('other networks and disconnected accounts do not request links', function (): void {
    Http::preventStrayRequests();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    publicationLinksPublication(provider: 'x');
    publicationLinksPublication(status: 'disconnected');
    Http::fake(['https://api.x.com/2/users/me*' => Http::response(['data' => []])]);

    $this->getJson('/api/state')->assertOk()->assertJsonCount(2, 'publications');
    Http::assertSentCount(1);
});
