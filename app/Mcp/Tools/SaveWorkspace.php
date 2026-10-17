<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspaces;
use Laravel\Mcp\Server\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SaveWorkspace extends Tool
{
    protected string $name = 'save_workspace';

    protected string $description = 'Create a named workspace, or pass workspace_id to rename it or change its icon. Icon accepts an emoji, symbol or initials. Does not switch the workspace open in the desktop app.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Omit to create a workspace. Supply an existing workspace id to edit.'), 'name' => $s->string()->required(), 'icon' => $s->string()];
    }

    public function handle(Request $r): Response
    {
        $data = $r->validate(['workspace_id' => 'sometimes|required|string|size:64', 'name' => 'required|string|max:100', 'icon' => 'nullable|string|max:32']);
        if (isset($data['workspace_id'])) {
            $data['id'] = $data['workspace_id'];
            unset($data['workspace_id']);
        }

        return Response::json(app(Workspaces::class)->save($data));
    }
}
