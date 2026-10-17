<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class DisconnectAccount extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'disconnect_account';

    protected string $description = 'Disconnect a social account, remove its stored provider credentials and cancel scheduled or retrying publications for that account.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'id' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Workspace::class)->disconnectAccount($r->all()));
    }
}
