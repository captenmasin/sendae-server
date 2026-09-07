<?php

namespace App\Mcp\Tools;

use App\Services\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SaveDraft extends Tool
{
    protected string $name = 'save_draft';

    protected string $description = 'Create or edit a draft. Supply a new UUID and version 0 to create. Otherwise use the current version. Content includes items, overrides, and account_ids. A conflict creates a copy and returns both versions.';

    public function schema(JsonSchema $s): array
    {
        return ['id' => $s->string()->required(), 'title' => $s->string()->required(), 'version' => $s->integer()->required(), 'content' => $s->object()->required()];
    }

    public function handle(Request $r): Response
    {
        return Response::json(app(Workspace::class)->save($r->all(), true));
    }
}
