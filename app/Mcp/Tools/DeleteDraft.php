<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class DeleteDraft extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'delete_draft';

    protected string $description = 'Delete a draft using its current version and cancel its pending publications. Published provider posts remain. Publishing or uncertain work blocks deletion.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'id' => $s->string()->required(), 'version' => $s->integer()->required()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Workspace::class)->deleteDraft($r->all()));
    }
}
