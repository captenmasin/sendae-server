<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspaces;
use Laravel\Mcp\Server\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class DeleteWorkspaceImage extends Tool
{
    protected string $name = 'delete_workspace_image';

    protected string $description = 'Remove the image from a workspace. Its symbol or initials remain as the fallback.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $data = $r->validate(['workspace_id' => 'required|string|size:64']);

        return Response::json(app(Workspaces::class)->deleteImage($data['workspace_id']));
    }
}
