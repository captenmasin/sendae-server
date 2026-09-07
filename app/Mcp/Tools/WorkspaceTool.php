<?php

namespace App\Mcp\Tools;

use App\Services\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkspaceTool extends Tool
{
    protected string $name = 'workspace';

    protected string $description = 'Read drafts, connected accounts, media IDs, posting slots, publication results and cached analytics. Credentials are excluded.';

    public function handle(Request $request): Response
    {
        return Response::json(app(Workspace::class)->state());
    }
}
