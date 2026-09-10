<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PublicationDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_publications_can_be_deleted_without_deleting_their_draft(): void
    {
        $this->postJson('/api/deletePublication', [])->assertUnauthorized();
        $user = User::factory()->create();
        Passport::actingAs($user, []);
        $this->postJson('/api/deletePublication', ['id' => (string) Str::uuid()])->assertForbidden();
        Passport::actingAs($user, ['mcp:use']);
        $this->postJson('/api/deletePublication', [])->assertUnprocessable();
        $this->postJson('/api/deletePublication', ['id' => 'invalid'])->assertUnprocessable();
        $draft = Draft::create(['content' => []]);
        $publication = Publication::create(['draft_id' => $draft->id, 'account_id' => (string) Str::uuid(), 'snapshot' => ['title' => 'Cancelled post'], 'scheduled_at' => now(), 'status' => 'cancelled', 'receipts' => ['already-published-item']]);

        $this->postJson('/api/deletePublication', ['id' => $publication->id])->assertJsonPath('deleted', true);
        $this->assertModelMissing($publication);
        $this->assertModelExists($draft);
        $this->getJson('/api/state')->assertJsonCount(0, 'publications');
        $this->postJson('/api/deletePublication', ['id' => $publication->id])->assertJsonPath('deleted', true);
        $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'reschedule', 'scheduled_at' => now()->addDay()->toIso8601String()])->assertUnprocessable();
    }

    public function test_non_cancelled_publications_return_422_and_remain_intact(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        foreach (['scheduled', 'retry', 'publishing', 'published', 'failed', 'missed', 'uncertain'] as $status) {
            $publication = Publication::create(['draft_id' => (string) Str::uuid(), 'account_id' => (string) Str::uuid(), 'snapshot' => [], 'scheduled_at' => now(), 'status' => $status]);

            $this->postJson('/api/deletePublication', ['id' => $publication->id])->assertUnprocessable()->assertJsonPath('errors.status.0', 'Only cancelled publications can be deleted.');
            $this->assertDatabaseHas('publications', ['id' => $publication->id, 'status' => $status]);
        }
    }

    public function test_deletion_cannot_remove_publications_from_another_workspace_or_owner(): void
    {
        $owner = User::factory()->create();
        Passport::actingAs($owner, ['mcp:use']);
        $publication = Publication::create(['draft_id' => (string) Str::uuid(), 'account_id' => (string) Str::uuid(), 'snapshot' => [], 'scheduled_at' => now(), 'status' => 'cancelled']);
        $second = $this->postJson('/api/workspaces', ['name' => 'Other workspace', 'icon' => 'B'])->assertCreated()->json('id');

        $this->withHeader('X-Workspace-Id', $second)->postJson('/api/deletePublication', ['id' => $publication->id])->assertJsonPath('deleted', true);
        $this->assertModelExists($publication);
        $other = User::factory()->create();
        Passport::actingAs($other, ['mcp:use']);
        $this->withHeader('X-Workspace-Id', $other->workspace_id)->postJson('/api/deletePublication', ['id' => $publication->id])->assertJsonPath('deleted', true);
        $this->assertModelExists($publication);
    }
}
