<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Workspace;
use Illuminate\Support\Str;
use App\Mcp\Tools\SaveDraft;
use App\Mcp\Tools\WorkspaceTool;
use App\Services\WorkspaceOwner;
use App\Mcp\Servers\SendaeServer;

test('workspace without an id lists every workspace and marks the account default', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $second = Workspace::create(['user_id' => $user->id, 'name' => 'Client work', 'icon' => 'C']);

    SendaeServer::tool(WorkspaceTool::class, [])->assertOk()->assertSee(json_encode(['workspaces' => [
        ['id' => $user->workspace_id, 'name' => 'Personal', 'default' => true],
        ['id' => $second->id, 'name' => 'Client work', 'default' => false],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

test('reading a workspace returns only that workspace and rejects another account', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $second = Workspace::create(['user_id' => $user->id, 'name' => 'Client work', 'icon' => 'C']);
    Draft::create(['title' => 'Personal draft', 'content' => ['items' => [['text' => 'Personal', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]]);
    app(WorkspaceOwner::class)->select($second->id);
    Draft::create(['title' => 'Client draft', 'content' => ['items' => [['text' => 'Client', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]]);

    SendaeServer::tool(WorkspaceTool::class, ['workspace_id' => $second->id])->assertOk()->assertSee('Client draft')->assertDontSee('Personal draft');
    SendaeServer::tool(WorkspaceTool::class, ['workspace_id' => User::factory()->create()->workspace_id])->assertHasErrors(['Choose a workspace id']);
});

test('save draft requires a workspace id when the account has one workspace', function (): void {
    $this->actingAs(User::factory()->create());

    SendaeServer::tool(SaveDraft::class, ['id' => (string) Str::uuid(), 'title' => 'Missing workspace', 'version' => 0, 'content' => ['items' => [['text' => 'Hello', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]])->assertHasErrors(['workspace id']);

    $this->assertDatabaseCount('drafts', 0);
});
