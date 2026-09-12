<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Draft;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Attachments;
use App\Services\Publisher;
use App\Services\WorkspaceOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WorkspaceDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_the_default_workspace_removes_its_data_and_switches_to_an_owned_workspace(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $other = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        $id = $user->workspace_id;
        $remaining = Workspace::create(['user_id' => $user->id, 'name' => 'Keep']);
        $keptDraft = app(WorkspaceOwner::class)->run($user->id, fn () => Draft::create(['title' => 'Keep draft', 'content' => []]), $remaining->id);
        $account = Account::create(['provider' => 'x', 'provider_id' => 'delete-me', 'name' => 'Delete account']);
        $draft = Draft::create(['title' => 'Delete draft', 'content' => []]);
        $draft->delete();
        $media = app(Attachments::class)->store(UploadedFile::fake()->image('upload.png'));
        $publication = Publication::create(['account_id' => $account->id, 'draft_id' => $draft->id, 'snapshot' => [], 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        $this->post('/api/workspaces/'.$id.'/image', ['file' => UploadedFile::fake()->image('avatar.png')])->assertOk();
        $avatar = Workspace::findOrFail($id)->image;

        $this->deleteJson('/api/workspaces/'.$id)->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('workspace_id', $remaining->id)->assertJsonCount(1, 'workspaces');

        $this->assertSame($remaining->id, $user->fresh()->workspace_id);
        $this->assertDatabaseMissing('workspaces', ['id' => $id]);
        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('drafts', ['id' => $draft->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('publications', ['id' => $publication->id]);
        $this->assertDatabaseHas('drafts', ['id' => $keptDraft->id, 'workspace_id' => $remaining->id]);
        $this->assertDatabaseHas('workspaces', ['id' => $other->workspace_id]);
        Storage::disk('local')->assertMissing([$media->path, $avatar]);
        app(Publisher::class)->publishAccount($account->id);
        Http::assertNothingSent();
        $this->withHeader('X-Workspace-Id', $remaining->id)->getJson('/api/state')->assertOk()->assertJsonPath('drafts.0.id', $keptDraft->id);
    }

    public function test_deletion_rejects_signed_out_other_owners_and_the_only_workspace(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->deleteJson('/api/workspaces/'.$user->workspace_id)->assertUnauthorized();
        Passport::actingAs($user, ['mcp:use']);
        $this->deleteJson('/api/workspaces/'.$other->workspace_id)->assertNotFound();
        $this->deleteJson('/api/workspaces/'.$user->workspace_id)->assertUnprocessable()->assertJsonPath('message', 'Create another workspace before deleting your only workspace.');
        $this->assertDatabaseHas('workspaces', ['id' => $user->workspace_id]);
        $this->assertDatabaseHas('workspaces', ['id' => $other->workspace_id]);
    }

    #[TestWith(['publishing'])]
    #[TestWith(['uncertain'])]
    public function test_deletion_keeps_workspace_and_receipts_when_publication_needs_resolution(string $status): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        Workspace::create(['user_id' => $user->id, 'name' => 'Keep']);
        $account = Account::create(['provider' => 'x', 'provider_id' => 'account', 'name' => 'Account']);
        $draft = Draft::create(['content' => []]);
        $publication = Publication::create(['account_id' => $account->id, 'draft_id' => $draft->id, 'snapshot' => [], 'receipts' => ['live-post'], 'status' => $status, 'scheduled_at' => now()]);

        $this->deleteJson('/api/workspaces/'.$user->workspace_id)->assertConflict()->assertJsonPath('message', 'Wait for publishing to finish and resolve uncertain posts before deleting this workspace.');

        $this->assertDatabaseHas('workspaces', ['id' => $user->workspace_id]);
        $this->assertSame(['live-post'], $publication->fresh()->receipts);
    }

    public function test_deleting_another_workspace_preserves_the_default_workspace(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        $workspace = Workspace::create(['user_id' => $user->id, 'name' => 'Remove']);

        $this->deleteJson('/api/workspaces/'.$workspace->id)->assertOk()->assertJsonPath('workspace_id', $user->workspace_id);

        $this->assertDatabaseHas('workspaces', ['id' => $user->workspace_id]);
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    }
}
