<?php

use App\Models\User;
use App\Models\Publication;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

function publicationReschedulingPublication(string $status = 'scheduled'): Publication
{
    return Publication::create([
        'draft_id' => (string) Str::uuid(),
        'account_id' => (string) Str::uuid(),
        'snapshot' => ['title' => 'Scheduled post', 'items' => [['text' => 'First'], ['text' => 'Second']]],
        'scheduled_at' => now()->addDay(),
        'status' => $status,
        'receipts' => ['confirmed-first-item'],
        'next_attempt_at' => now()->addMinutes(10),
        'error' => 'Temporary provider failure',
    ])->refresh();
}

test('queued posts can change time without changing snapshot or confirmed items', function (): void {
    $this->freezeTime();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $at = now()->addDays(3)->startOfSecond()->setTimezone('Europe/London');
    foreach (['scheduled', 'retry'] as $status) {
        $publication = publicationReschedulingPublication($status);
        $other = publicationReschedulingPublication();
        $originalTime = $other->scheduled_at;
        $payload = ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => $at->toIso8601String()];

        $this->postJson('/api/recover', $payload)->assertJsonPath('id', $publication->id)->assertJsonPath('status', 'scheduled');
        $this->postJson('/api/recover', $payload)->assertJsonPath('id', $publication->id);

        $updated = $publication->fresh();
        $this->assertTrue($updated->scheduled_at->equalTo($at));
        $this->assertSame($publication->snapshot, $updated->snapshot);
        $this->assertSame($publication->receipts, $updated->receipts);
        $this->assertNull($updated->next_attempt_at);
        $this->assertNull($updated->error);
        $this->assertTrue($other->fresh()->scheduled_at->equalTo($originalTime));
    }
    $this->assertDatabaseCount('publications', 4);
});

test('in flight and completed posts cannot be moved', function (): void {
    $this->freezeTime();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    foreach (['publishing', 'published', 'uncertain'] as $status) {
        $publication = publicationReschedulingPublication($status);
        $original = $publication->getAttributes();

        $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(2)->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('errors.status.0', 'This publication cannot be rescheduled in its current state.');

        $this->assertSame($original, $publication->fresh()->getAttributes());
    }
});

test('invalid or past times leave the original schedule intact', function (): void {
    $this->freezeTime();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $publication = publicationReschedulingPublication();
    $original = $publication->getAttributes();
    foreach ([null, 'invalid', now()->subMinute()->toIso8601String(), now()->toIso8601String()] as $at) {
        $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => $at])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

        $this->assertSame($original, $publication->fresh()->getAttributes());
    }
});

test('rescheduling requires access to the publications workspace', function (): void {
    $this->postJson('/api/recover', [])->assertUnauthorized();
    $user = User::factory()->create();
    Passport::actingAs($user, []);
    $this->postJson('/api/recover', [])->assertForbidden();
    Passport::actingAs($user, ['mcp:use']);
    $publication = publicationReschedulingPublication();
    $original = $publication->getAttributes();
    $otherWorkspace = $this->postJson('/api/workspaces', ['name' => 'Other', 'icon' => 'B'])->assertCreated()->json('id');

    $this->withHeader('X-Workspace-Id', $otherWorkspace)
        ->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(2)->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('id');

    $this->assertSame($original, Publication::withoutGlobalScope('owner')->findOrFail($publication->id)->getAttributes());
});
