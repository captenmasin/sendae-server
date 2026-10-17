<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class PreviewSchedule extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'preview_schedule';

    protected string $description = 'Preview the next available weekly posting slot for each selected account without creating publications. Uses the current draft version and validates content and destinations.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'draft_id' => $s->string()->required(), 'version' => $s->integer()->required()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Workspace::class)->previewSchedule($r->all()));
    }
}
