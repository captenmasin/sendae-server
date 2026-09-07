<?php

namespace App\Mcp\Tools;

use App\Services\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SchedulePost extends Tool
{
    protected string $name = 'schedule_post';

    protected string $description = 'Schedule the draft for all its selected accounts. mode exact requires scheduled_at with explicit timezone, queue uses each account’s next weekly slot, now publishes at the next worker run. No separate in-app approval. Returns confirmed publication IDs and times.';

    public function schema(JsonSchema $s): array
    {
        return ['draft_id' => $s->string()->required(), 'version' => $s->integer()->required(), 'mode' => $s->string()->enum(['exact', 'queue', 'now'])->required(), 'scheduled_at' => $s->string()];
    }

    public function handle(Request $r): Response
    {
        return Response::json(app(Workspace::class)->schedule($r->all()));
    }
}
