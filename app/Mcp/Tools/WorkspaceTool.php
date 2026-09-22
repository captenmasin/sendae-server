<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Request;
use App\Models\Workspace;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Services\WorkspaceOwner;
use App\Mcp\Concerns\SelectsWorkspace;
use App\Services\Workspace as WorkspaceState;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class WorkspaceTool extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'workspace';

    protected string $description = 'Call with no arguments to list workspaces (id, name, default). Pass workspace_id to read that workspace’s drafts, connected accounts, media IDs, posting slots, publication results and cached analytics. Credentials are excluded.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Omit to list workspaces. Pass an id from that list to read one workspace.')];
    }

    public function handle(Request $r): Response
    {
        if (! is_string($r->get('workspace_id')) || $r->get('workspace_id') === '') {
            $owner = app(WorkspaceOwner::class)->requireId();
            $default = User::findOrFail($owner)->workspace_id;

            return Response::json(['workspaces' => Workspace::query()->where('user_id', $owner)->orderBy('created_at')->get(['id', 'name'])->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'default' => $workspace->id === $default,
            ])->all()]);
        }

        $this->selectWorkspace($r);

        return Response::json(app(WorkspaceState::class)->state());
    }
}
