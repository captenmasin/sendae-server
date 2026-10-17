<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class DeletePublication extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'delete_publication';

    protected string $description = 'Delete a cancelled publication from Sendae history. Other statuses cannot be deleted. Does not delete a live social post.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'id' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        $data = $r->validate(['id' => 'required|uuid']);

        return Response::json(app(Workspace::class)->deletePublication($data['id']));
    }
}
