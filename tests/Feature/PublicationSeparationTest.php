<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Publication;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/** @return array{Draft, Publication, Publication} */
function sharedPost(): array
{
    $accounts = [(string) Str::uuid(), (string) Str::uuid()];
    $draft = Draft::create(['title' => 'Shared draft', 'content' => [
        'items' => [['text' => 'Shared content', 'media_ids' => []]],
        'overrides' => ['x' => [['text' => 'Network content', 'media_ids' => []]]],
        'account_ids' => $accounts,
    ]])->refresh();
    $snapshot = ['title' => 'Scheduled title', 'items' => [['text' => 'Scheduled network content', 'media_ids' => [(string) Str::uuid()]]]];
    $publication = Publication::create(['draft_id' => $draft->id, 'account_id' => $accounts[0], 'snapshot' => $snapshot, 'scheduled_at' => now()->addDay(), 'status' => 'retry', 'receipts' => ['confirmed-item']]);
    $other = Publication::create(['draft_id' => $draft->id, 'account_id' => $accounts[1], 'snapshot' => $snapshot, 'scheduled_at' => now()->addDay(), 'status' => 'scheduled', 'receipts' => []]);

    return [$draft, $publication, $other];
}

test('individual changes create an independent post and retries do not duplicate it', function (string $action, string $status): void {
    $this->freezeTime();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    [$draft, $publication, $other] = sharedPost();
    $originalOther = $other->refresh()->getAttributes();
    $payload = ['id' => $publication->id, 'separate' => true, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(3)->toIso8601String()];

    $response = $this->postJson('/api/'.$action, $payload)->assertJsonPath('status', $status);
    $newId = $response->json('draft_id');
    $this->assertNotSame($draft->id, $newId);
    $this->postJson('/api/'.$action, $payload)->assertJsonPath('draft_id', $newId);

    $newDraft = Draft::findOrFail($newId);
    $this->assertSame('Scheduled title', $newDraft->title);
    $this->assertSame(['items' => $publication->snapshot['items'], 'overrides' => [], 'account_ids' => [$publication->account_id]], $newDraft->content);
    $this->assertSame([$other->account_id], $draft->fresh()->content['account_ids']);
    $this->assertSame($draft->content['items'], $draft->fresh()->content['items']);
    $this->assertSame($draft->version + 1, $draft->fresh()->version);
    $this->assertSame($originalOther, $other->fresh()->getAttributes());
    $this->assertSame($publication->snapshot, $publication->fresh()->snapshot);
    $this->assertSame(['confirmed-item'], $publication->fresh()->receipts);
    $this->assertSame($draft->user_id, $newDraft->user_id);
    $this->assertSame($draft->workspace_id, $newDraft->workspace_id);
    $this->assertDatabaseCount('drafts', 2);
    $this->assertDatabaseCount('publications', 2);
})->with([
    ['recover', 'scheduled'],
    ['cancel', 'cancelled'],
]);

test('cancelling the whole post keeps one draft', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    [$draft, $publication, $other] = sharedPost();

    foreach ([$publication, $other] as $post) {
        $this->postJson('/api/cancel', ['id' => $post->id])->assertJsonPath('draft_id', $draft->id)->assertJsonPath('status', 'cancelled');
    }

    $this->assertSame($draft->content, $draft->fresh()->content);
    $this->assertDatabaseCount('drafts', 1);
});

test('rejected changes leave the shared post intact', function (string $action): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    [$draft, $publication] = sharedPost();
    $publication->update(['status' => 'publishing']);

    $this->postJson('/api/'.$action, ['id' => $publication->id, 'separate' => true, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(3)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->assertSame($draft->content, $draft->fresh()->content);
    $this->assertSame($draft->id, $publication->fresh()->draft_id);
    $this->assertDatabaseCount('drafts', 1);
})->with([
    ['recover'],
    ['cancel'],
]);

test('separating cannot access another users post', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    [$draft, $publication] = sharedPost();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $this->postJson('/api/cancel', ['id' => $publication->id, 'separate' => true])->assertNotFound();
    $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(3)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('id');

    $this->assertDatabaseCount('drafts', 1);
    $this->assertDatabaseHas('publications', ['id' => $publication->id, 'draft_id' => $draft->id, 'status' => 'retry']);
});
