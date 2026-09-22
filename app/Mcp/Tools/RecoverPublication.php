<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class RecoverPublication extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'recover_publication';

    protected string $description = 'Recover unfinished work. confirmed verifies post_id on the provider and records the next uncertain thread item. not_published asserts you verified no post was published, and requires a new scheduled_at. reschedule moves a missed/failed/cancelled publication to scheduled_at. Confirmed thread items are retained. Never assert not_published without checking the actual provider.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'id' => $s->string()->required(), 'action' => $s->string()->enum(['confirmed', 'not_published', 'reschedule'])->required(), 'post_id' => $s->string(), 'scheduled_at' => $s->string()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Workspace::class)->recover($r->all()));
    }
}
