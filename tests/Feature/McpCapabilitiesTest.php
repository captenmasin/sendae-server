<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Account;
use App\Models\Workspace;
use App\Models\Publication;
use Illuminate\Support\Str;
use App\Mcp\Tools\SaveDraft;
use App\Mcp\Tools\DeleteDraft;
use Laravel\Passport\Passport;
use App\Mcp\Tools\SchedulePost;
use App\Mcp\Tools\SaveWorkspace;
use App\Mcp\Servers\SendaeServer;
use Illuminate\Http\UploadedFile;
use App\Mcp\Tools\DeleteWorkspace;
use App\Mcp\Tools\PreviewSchedule;
use App\Mcp\Tools\CancelPublication;
use App\Mcp\Tools\DeletePublication;
use App\Mcp\Tools\DisconnectAccount;
use Illuminate\Support\Facades\Http;
use App\Mcp\Tools\SaveWorkspaceImage;
use App\Mcp\Tools\DeleteWorkspaceImage;
use Illuminate\Support\Facades\Storage;

/** @return array{Account, Draft} */
function mcpPost(): array
{
    $account = Account::create(['provider' => 'x', 'provider_id' => (string) Str::uuid(), 'name' => 'Test account', 'status' => 'connected', 'timezone' => 'UTC', 'slots' => [['day' => 1, 'time' => '09:00']], 'credentials' => ['access_token' => 'private-token']]);
    $draft = Draft::create(['title' => 'Original', 'content' => ['items' => [['text' => 'Original content', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]])->refresh();

    return [$account, $draft];
}

test('MCP previews queue times and updates scheduled snapshots without moving their times', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 18)->setTime(12, 0));
    $this->actingAs($user = User::factory()->create());
    Http::preventStrayRequests();
    [$account, $draft] = mcpPost();
    $input = ['workspace_id' => $user->workspace_id, 'draft_id' => $draft->id, 'version' => $draft->version];

    SendaeServer::tool(PreviewSchedule::class, $input)->assertOk()->assertSee('2026-10-19T09:00:00');
    $this->assertDatabaseCount('publications', 0);
    SendaeServer::tool(SchedulePost::class, [...$input, 'mode' => 'queue'])->assertOk();
    $publication = Publication::firstOrFail();
    $time = $publication->scheduled_at->toIso8601String();
    SendaeServer::tool(SaveDraft::class, ['workspace_id' => $user->workspace_id, 'id' => $draft->id, 'version' => $draft->version, 'title' => 'Updated', 'content' => [...$draft->content, 'items' => [['text' => 'Updated content', 'media_ids' => []]]]])->assertOk();
    $update = [...$input, 'version' => $draft->fresh()->version, 'mode' => 'preserve', 'update' => true, 'request_id' => (string) Str::uuid()];
    SendaeServer::tool(SchedulePost::class, $update)->assertOk();
    SendaeServer::tool(SchedulePost::class, $update)->assertOk();

    expect($publication->fresh()->snapshot['items'][0]['text'])->toBe('Updated content');
    expect($publication->fresh()->scheduled_at->toIso8601String())->toBe($time);
    $this->assertDatabaseCount('publications', 1);
});

test('MCP draft deletion checks versions and refuses uncertain work', function (): void {
    $this->actingAs($user = User::factory()->create());
    [$account, $draft] = mcpPost();
    $publication = Publication::create(['draft_id' => $draft->id, 'account_id' => $account->id, 'snapshot' => [], 'scheduled_at' => now()->addDay(), 'status' => 'uncertain']);
    $input = ['workspace_id' => $user->workspace_id, 'id' => $draft->id, 'version' => $draft->version];

    SendaeServer::tool(DeleteDraft::class, [...$input, 'version' => 999])->assertHasErrors(['Sync and review']);
    SendaeServer::tool(DeleteDraft::class, $input)->assertHasErrors(['needs recovery']);
    $this->assertNotSoftDeleted($draft);
    $publication->update(['status' => 'scheduled']);
    SendaeServer::tool(DeleteDraft::class, $input)->assertOk();

    $this->assertSoftDeleted($draft);
    expect($publication->fresh()->status)->toBe('cancelled');
});

test('MCP separates an unscheduled destination and deletes only cancelled publications', function (): void {
    $this->actingAs($user = User::factory()->create());
    [$first, $draft] = mcpPost();
    [$second] = mcpPost();
    $draft->update(['content' => [...$draft->content, 'account_ids' => [$first->id, $second->id]]]);
    $snapshot = ['title' => 'Scheduled title', 'items' => [['text' => 'Scheduled content', 'media_ids' => []]]];
    $publication = Publication::create(['draft_id' => $draft->id, 'account_id' => $first->id, 'snapshot' => $snapshot, 'scheduled_at' => now()->addDay(), 'status' => 'scheduled']);
    $other = Publication::create(['draft_id' => $draft->id, 'account_id' => $second->id, 'snapshot' => $snapshot, 'scheduled_at' => now()->addDay(), 'status' => 'scheduled']);
    $input = ['workspace_id' => $user->workspace_id, 'id' => $publication->id];

    SendaeServer::tool(DeletePublication::class, $input)->assertHasErrors(['Only cancelled']);
    SendaeServer::tool(CancelPublication::class, [...$input, 'separate' => true])->assertOk();
    expect($publication->fresh()->draft_id)->not->toBe($draft->id);
    expect($other->fresh()->only(['draft_id', 'status']))->toBe(['draft_id' => $draft->id, 'status' => 'scheduled']);
    expect($draft->fresh()->content['account_ids'])->toBe([$second->id]);
    SendaeServer::tool(DeletePublication::class, $input)->assertOk();
    $this->assertModelMissing($publication);
});

test('MCP disconnection clears credentials and cancels pending posts', function (): void {
    $this->actingAs($user = User::factory()->create());
    [$account, $draft] = mcpPost();
    $pending = Publication::create(['draft_id' => $draft->id, 'account_id' => $account->id, 'snapshot' => [], 'scheduled_at' => now()->addDay(), 'status' => 'scheduled']);
    $published = Publication::create(['draft_id' => $draft->id, 'account_id' => $account->id, 'snapshot' => [], 'scheduled_at' => now()->subDay(), 'status' => 'published']);

    SendaeServer::tool(DisconnectAccount::class, ['workspace_id' => $user->workspace_id, 'id' => $account->id])->assertOk();

    expect($account->fresh()->status)->toBe('disconnected');
    expect($account->fresh()->credentials)->toBeNull();
    expect($pending->fresh()->status)->toBe('cancelled');
    expect($published->fresh()->status)->toBe('published');
});

test('MCP creates renames and deletes workspaces and replaces and removes their images', function (): void {
    Storage::fake('local');
    $this->actingAs($user = User::factory()->create());
    SendaeServer::tool(SaveWorkspace::class, ['name' => 'Client work'])->assertOk()->assertSee('Client work');
    $workspace = Workspace::where('name', 'Client work')->firstOrFail();
    $input = ['workspace_id' => $workspace->id];
    SendaeServer::tool(SaveWorkspace::class, [...$input, 'name' => 'Renamed', 'icon' => 'R'])->assertOk();
    expect($workspace->fresh()->only(['name', 'icon']))->toBe(['name' => 'Renamed', 'icon' => 'R']);
    $png = UploadedFile::fake()->image('logo.png')->get();
    SendaeServer::tool(SaveWorkspaceImage::class, [...$input, 'name' => 'logo.png', 'base64' => base64_encode($png)])->assertOk()->assertSee('true');
    $firstPath = $workspace->fresh()->image;
    Storage::disk('local')->assertExists($firstPath);
    $jpg = UploadedFile::fake()->image('logo.jpg')->get();
    SendaeServer::tool(SaveWorkspaceImage::class, [...$input, 'name' => 'logo.jpg', 'base64' => base64_encode($jpg)])->assertOk();
    Storage::disk('local')->assertMissing($firstPath);
    $path = $workspace->fresh()->image;
    SendaeServer::tool(DeleteWorkspaceImage::class, $input)->assertOk();
    Storage::disk('local')->assertMissing($path);
    expect($workspace->fresh()->image)->toBeNull();
    SendaeServer::tool(DeleteWorkspace::class, $input)->assertOk();
    $this->assertModelMissing($workspace);
    SendaeServer::tool(DeleteWorkspace::class, ['workspace_id' => $user->workspace_id])->assertHasErrors(['only workspace']);
});

test('MCP rejects invalid workspace images', function (string $bytes): void {
    $this->actingAs($user = User::factory()->create());
    SendaeServer::tool(SaveWorkspaceImage::class, ['workspace_id' => $user->workspace_id, 'name' => 'logo.png', 'base64' => $bytes])->assertHasErrors();
    expect(Workspace::findOrFail($user->workspace_id)->image)->toBeNull();
})->with(['malformed base64' => 'not base64!', 'text file' => base64_encode('not an image'), 'oversized' => base64_encode(str_repeat('x', 2097153))]);

test('new MCP mutations reject another users workspace', function (string $tool, array $fields): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);
    SendaeServer::tool($tool, [...$fields, 'workspace_id' => $other->workspace_id])->assertHasErrors();
    $this->assertModelExists(Workspace::findOrFail($other->workspace_id));
})->with([
    [SaveWorkspace::class, ['name' => 'Stolen']],
    [DeleteWorkspace::class, []],
    [SaveWorkspaceImage::class, ['name' => 'image.png', 'base64' => base64_encode('not an image')]],
    [DeleteWorkspaceImage::class, []],
    [DisconnectAccount::class, ['id' => (string) Str::uuid()]],
    [DeleteDraft::class, ['id' => (string) Str::uuid(), 'version' => 0]],
    [DeletePublication::class, ['id' => (string) Str::uuid()]],
    [PreviewSchedule::class, ['draft_id' => (string) Str::uuid(), 'version' => 0]],
]);

test('hosted MCP advertises every new tool across pages and requires authentication', function (): void {
    require base_path('routes/ai.php');
    $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
    $this->postJson('/mcp', $request)->assertUnauthorized();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $tools = [];
    $cursor = null;
    do {
        $page = $this->postJson('/mcp', [...$request, 'params' => $cursor === null ? [] : ['cursor' => $cursor]])->assertOk()->json('result');
        $tools = [...$tools, ...$page['tools']];
        $cursor = $page['nextCursor'] ?? null;
    } while ($cursor !== null);

    expect(array_column($tools, 'name'))->toContain('preview_schedule', 'delete_draft', 'delete_publication', 'save_workspace', 'delete_workspace', 'save_workspace_image', 'delete_workspace_image', 'disconnect_account');
});

test('MCP workspace image writes reject another owner even with a valid image', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);
    $file = UploadedFile::fake()->image('logo.png');

    SendaeServer::tool(SaveWorkspaceImage::class, ['workspace_id' => $other->workspace_id, 'name' => 'logo.png', 'base64' => base64_encode($file->get())])->assertHasErrors();

    expect(Workspace::findOrFail($other->workspace_id)->image)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
