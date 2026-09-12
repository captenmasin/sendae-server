<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublicationSeparationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Draft, Publication, Publication} */
    private function sharedPost(): array
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

    #[TestWith(['recover', 'scheduled'])]
    #[TestWith(['cancel', 'cancelled'])]
    public function test_individual_changes_create_an_independent_post_and_retries_do_not_duplicate_it(string $action, string $status): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        [$draft, $publication, $other] = $this->sharedPost();
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
    }

    public function test_cancelling_the_whole_post_keeps_one_draft(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        [$draft, $publication, $other] = $this->sharedPost();

        foreach ([$publication, $other] as $post) {
            $this->postJson('/api/cancel', ['id' => $post->id])->assertJsonPath('draft_id', $draft->id)->assertJsonPath('status', 'cancelled');
        }

        $this->assertSame($draft->content, $draft->fresh()->content);
        $this->assertDatabaseCount('drafts', 1);
    }

    #[TestWith(['recover'])]
    #[TestWith(['cancel'])]
    public function test_rejected_changes_leave_the_shared_post_intact(string $action): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        [$draft, $publication] = $this->sharedPost();
        $publication->update(['status' => 'publishing']);

        $this->postJson('/api/'.$action, ['id' => $publication->id, 'separate' => true, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(3)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame($draft->content, $draft->fresh()->content);
        $this->assertSame($draft->id, $publication->fresh()->draft_id);
        $this->assertDatabaseCount('drafts', 1);
    }

    public function test_separating_cannot_access_another_users_post(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        [$draft, $publication] = $this->sharedPost();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $this->postJson('/api/cancel', ['id' => $publication->id, 'separate' => true])->assertNotFound();
        $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => now()->addDays(3)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('id');

        $this->assertDatabaseCount('drafts', 1);
        $this->assertDatabaseHas('publications', ['id' => $publication->id, 'draft_id' => $draft->id, 'status' => 'retry']);
    }
}
