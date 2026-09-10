<?php

namespace Tests\Feature;

use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PublicationReschedulingTest extends TestCase
{
    use RefreshDatabase;

    private function publication(string $status = 'scheduled'): Publication
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

    public function test_queued_posts_can_change_time_without_changing_snapshot_or_confirmed_items(): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $at = now()->addDays(3)->startOfSecond()->setTimezone('Europe/London');
        foreach (['scheduled', 'retry'] as $status) {
            $publication = $this->publication($status);
            $other = $this->publication();
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
    }

    public function test_in_flight_and_completed_posts_cannot_be_moved(): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        foreach (['publishing', 'published', 'uncertain'] as $status) {
            $publication = $this->publication($status);
            $original = $publication->getAttributes();

            $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(2)->toIso8601String()])
                ->assertUnprocessable()->assertJsonPath('errors.status.0', 'This publication cannot be rescheduled in its current state.');

            $this->assertSame($original, $publication->fresh()->getAttributes());
        }
    }

    public function test_invalid_or_past_times_leave_the_original_schedule_intact(): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $publication = $this->publication();
        $original = $publication->getAttributes();
        foreach ([null, 'invalid', now()->subMinute()->toIso8601String(), now()->toIso8601String()] as $at) {
            $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => $at])
                ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

            $this->assertSame($original, $publication->fresh()->getAttributes());
        }
    }

    public function test_rescheduling_requires_access_to_the_publications_workspace(): void
    {
        $this->postJson('/api/recover', [])->assertUnauthorized();
        $user = User::factory()->create();
        Passport::actingAs($user, []);
        $this->postJson('/api/recover', [])->assertForbidden();
        Passport::actingAs($user, ['mcp:use']);
        $publication = $this->publication();
        $original = $publication->getAttributes();
        $otherWorkspace = $this->postJson('/api/workspaces', ['name' => 'Other', 'icon' => 'B'])->assertCreated()->json('id');

        $this->withHeader('X-Workspace-Id', $otherWorkspace)
            ->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(2)->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('id');

        $this->assertSame($original, Publication::withoutGlobalScope('owner')->findOrFail($publication->id)->getAttributes());
    }
}
