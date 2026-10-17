<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspaces;
use Laravel\Mcp\Server\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class DeleteWorkspace extends Tool
{
    protected string $name = 'delete_workspace';

    protected string $description = 'Permanently delete a workspace and all its drafts, accounts, media and publication history. Does not delete live social posts. Cannot delete the only workspace or one with publishing or uncertain posts.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $data = $r->validate(['workspace_id' => 'required|string|size:64']);

        return Response::json(app(Workspaces::class)->delete($data['workspace_id']));
    }
}
