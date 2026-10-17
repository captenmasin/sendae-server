<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Account;
use App\Models\Publication;
use Illuminate\Support\Str;
use App\Mcp\Tools\SchedulePost;
use App\Mcp\Servers\SendaeServer;
use App\Mcp\Tools\CancelPublication;
use Illuminate\Support\Facades\Http;

test('scheduling tool advertises optional identity and schedule update controls', function (): void {
    $schema = app(SchedulePost::class)->toArray()['inputSchema'];

    expect($schema['properties']['mode']['enum'])->toBe(['exact', 'queue', 'now', 'preserve']);
    expect($schema['properties']['request_id']['type'])->toBe('string');
    expect($schema['properties']['update']['type'])->toBe('boolean');
    expect($schema['required'])->not->toContain('request_id', 'update');
});

test('MCP replays scheduling receipts after cancellation and a new identity schedules again', function (bool $identified): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $this->actingAs($user);
    Http::preventStrayRequests();
    $account = Account::create(['provider' => 'x', 'provider_id' => '123', 'name' => 'X', 'status' => 'connected']);
    $draft = Draft::create(['title' => 'Draft', 'content' => ['items' => [['text' => 'Hello', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]]);
    $payload = ['workspace_id' => $user->workspace_id, 'draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()];
    if ($identified) {
        $payload['request_id'] = (string) Str::uuid();
    }

    SendaeServer::tool(SchedulePost::class, $payload)->assertOk();
    $first = Publication::sole();
    SendaeServer::tool(CancelPublication::class, ['workspace_id' => $user->workspace_id, 'id' => $first->id])->assertOk();
    SendaeServer::tool(SchedulePost::class, $payload)->assertOk()->assertSee($first->id);

    $this->assertDatabaseCount('publications', 1);
    expect($first->fresh()->status)->toBe('cancelled');

    $payload['request_id'] = (string) Str::uuid();
    SendaeServer::tool(SchedulePost::class, $payload)->assertOk();
    $second = Publication::where('status', 'scheduled')->sole();
    SendaeServer::tool(SchedulePost::class, $payload)->assertOk()->assertSee($second->id);

    expect($second->id)->not->toBe($first->id);
    $this->assertDatabaseCount('publications', 2);
    $this->assertDatabaseCount('operation_receipts', 2);
})->with(['explicit identity' => true, 'legacy omitted identity' => false]);

test('MCP updates queued snapshots with preserved or new times and safely replays earlier updates', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $this->actingAs($user);
    Http::preventStrayRequests();
    $account = Account::create(['provider' => 'x', 'provider_id' => '123', 'name' => 'X', 'status' => 'connected']);
    $draft = Draft::create(['title' => 'Draft', 'content' => ['items' => [['text' => 'Original', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]]);
    $initial = ['workspace_id' => $user->workspace_id, 'draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String(), 'request_id' => (string) Str::uuid()];
    SendaeServer::tool(SchedulePost::class, $initial)->assertOk();
    $publication = Publication::sole();
    $draft->update(['version' => 2, 'content' => [...$draft->content, 'items' => [['text' => 'Updated', 'media_ids' => []]]]]);
    $preserve = ['workspace_id' => $user->workspace_id, 'draft_id' => $draft->id, 'version' => 2, 'mode' => 'preserve', 'update' => true, 'request_id' => (string) Str::uuid()];

    SendaeServer::tool(SchedulePost::class, $preserve)->assertOk()->assertSee($publication->id);

    expect($publication->fresh()->snapshot['items'][0]['text'])->toBe('Updated');
    expect($publication->fresh()->scheduled_at->equalTo($publication->scheduled_at))->toBeTrue();
    $move = [...$preserve, 'mode' => 'exact', 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'request_id' => (string) Str::uuid()];
    SendaeServer::tool(SchedulePost::class, $move)->assertOk()->assertSee($publication->id);
    SendaeServer::tool(SchedulePost::class, $preserve)->assertOk()->assertSee($publication->id);

    expect($publication->fresh()->scheduled_at->equalTo(now()->addDays(2)->startOfSecond()))->toBeTrue();
    $this->assertDatabaseCount('publications', 1);
    $this->assertDatabaseCount('operation_receipts', 3);
});

test('MCP rejects invalid identity and preserve without update before creating publications', function (array $invalid): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $draft = Draft::create(['title' => 'Draft', 'content' => ['items' => [], 'overrides' => [], 'account_ids' => []]]);
    $payload = ['workspace_id' => $user->workspace_id, 'draft_id' => $draft->id, 'version' => 1, 'mode' => 'now'];

    SendaeServer::tool(SchedulePost::class, [...$payload, ...$invalid])->assertHasErrors();

    $this->assertDatabaseCount('publications', 0);
    $this->assertDatabaseCount('operation_receipts', 0);
})->with([
    'invalid identity' => [['request_id' => 'invalid']],
    'preserve without update' => [['mode' => 'preserve']],
]);
