<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Draft;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DraftDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletion_unschedules_publications_and_cannot_be_undone_by_stale_sync(): void
    {
        $this->postJson('/api/deleteDraft', [])->assertUnauthorized();
        $owner = User::factory()->create();
        Passport::actingAs($owner, ['mcp:use']);
        $this->postJson('/api/deleteDraft', [])->assertUnprocessable();
        $account = Account::create(['name' => 'Account', 'provider' => 'x', 'provider_id' => '123']);
        $payload = ['id' => (string) Str::uuid(), 'title' => 'Draft to delete', 'version' => 0, 'content' => ['items' => [['text' => 'Scheduled content', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]];
        $this->postJson('/api/drafts', $payload)->assertOk();
        $publication = $this->postJson('/api/schedule', ['draft_id' => $payload['id'], 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()])->assertOk()->json('0');
        $this->postJson('/api/deleteDraft', ['id' => $payload['id'], 'version' => 0])->assertConflict();
        $this->assertNotSoftDeleted('drafts', ['id' => $payload['id']]);

        $this->postJson('/api/deleteDraft', ['id' => $payload['id'], 'version' => 1])->assertJsonPath('deleted', true)->assertJsonPath('cancelled_publication_ids.0', $publication['id']);
        $this->postJson('/api/deleteDraft', ['id' => $payload['id'], 'version' => 1])->assertOk();
        $this->assertSoftDeleted('drafts', ['id' => $payload['id']]);
        $this->getJson('/api/state')->assertJsonCount(0, 'drafts')->assertJsonPath('deleted_draft_ids.0', $payload['id']);
        $this->postJson('/api/drafts', $payload)->assertGone();
        $this->postJson('/api/schedule', ['draft_id' => $payload['id'], 'version' => 1, 'mode' => 'queue'])->assertNotFound();
        $this->assertSame('cancelled', Publication::findOrFail($publication['id'])->status);
        $this->assertSame('Scheduled content', Publication::findOrFail($publication['id'])->snapshot['items'][0]['text']);

    }

    public function test_deletion_cancels_all_queued_destinations_but_refuses_in_flight_or_uncertain_posts(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $draft = Draft::create(['content' => [], 'version' => 1]);
        $account = Account::create(['name' => 'Account', 'provider' => 'x', 'provider_id' => '123']);
        $queued = [];
        foreach (['scheduled', 'retry'] as $status) {
            $queued[] = Publication::create(['draft_id' => $draft->id, 'account_id' => $account->id, 'status' => $status, 'snapshot' => [], 'scheduled_at' => '2027-01-01 12:00:00']);
        }
        $active = Publication::create(['draft_id' => $draft->id, 'account_id' => $account->id, 'status' => 'publishing', 'snapshot' => [], 'scheduled_at' => '2027-01-01 12:00:00']);
        foreach (['publishing', 'uncertain'] as $status) {
            $active->update(['status' => $status]);
            $this->postJson('/api/deleteDraft', ['id' => $draft->id, 'version' => 1])->assertConflict();
            $this->assertNotSoftDeleted($draft);
            $this->assertSame('scheduled', $queued[0]->fresh()->status);
            $this->assertSame('retry', $queued[1]->fresh()->status);
        }

        $active->update(['status' => 'published']);
        $this->postJson('/api/deleteDraft', ['id' => $draft->id, 'version' => 1])->assertOk()->assertJsonCount(2, 'cancelled_publication_ids');

        $this->assertSoftDeleted($draft);
        $this->assertSame('cancelled', $queued[0]->fresh()->status);
        $this->assertSame('cancelled', $queued[1]->fresh()->status);
        $this->assertSame('published', $active->fresh()->status);
    }

    public function test_deleting_an_unknown_or_other_customers_draft_cannot_change_their_workspace(): void
    {
        $owner = User::factory()->create();
        Passport::actingAs($owner, ['mcp:use']);
        $draft = Draft::create(['content' => []]);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $this->postJson('/api/deleteDraft', ['id' => $draft->id, 'version' => 0])->assertJsonPath('deleted', true);
        $this->postJson('/api/deleteDraft', ['id' => (string) Str::uuid(), 'version' => 0])->assertJsonPath('deleted', true);
        $this->getJson('/api/state')->assertJsonCount(0, 'deleted_draft_ids');
        $this->assertNotSoftDeleted('drafts', ['id' => $draft->id]);
    }
}
