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

    protected string $description = 'Schedule the draft for all its selected accounts. mode exact requires scheduled_at with explicit timezone, queue uses each account’s next weekly slot, now publishes at the next worker run. Set update=true to update existing queued content, destinations or times. mode preserve keeps existing times and requires update=true. Supply a new request_id UUID for each intentional scheduling action and reuse it with the same arguments for retries. Without a new request_id, identical arguments replay the earlier receipt even after cancellation. No separate in-app approval. Returns recorded publication IDs and times; read workspace for current status.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'draft_id' => $s->string()->required(), 'version' => $s->integer()->required(), 'mode' => $s->string()->enum(['exact', 'queue', 'now', 'preserve'])->description('preserve requires update=true and keeps existing scheduled times.')->required(), 'scheduled_at' => $s->string(), 'update' => $s->boolean()->description('Update pending publication snapshots and destinations. Omit or use false for a new schedule. Use mode preserve to keep existing times.'), 'request_id' => $s->string()->description('Optional UUID: new for each intentional action, reused with identical arguments for retries. Omitting it replays identical requests, including after cancellation.')];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Workspace::class)->schedule($r->all()));
    }
}
