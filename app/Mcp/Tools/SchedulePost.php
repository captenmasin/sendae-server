<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SchedulePost extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'schedule_post';

    protected string $description = 'Schedule the draft for all its selected accounts. mode exact requires scheduled_at with explicit timezone, queue uses each account’s next weekly slot, now publishes at the next worker run. No separate in-app approval. Returns confirmed publication IDs and times.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'draft_id' => $s->string()->required(), 'version' => $s->integer()->required(), 'mode' => $s->string()->enum(['exact', 'queue', 'now'])->required(), 'scheduled_at' => $s->string()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Workspace::class)->schedule($r->all()));
    }
}
