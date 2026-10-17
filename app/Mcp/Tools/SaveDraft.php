<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SaveDraft extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'save_draft';

    protected string $description = 'Create or edit a draft. Supply a new UUID and version 0 to create. Otherwise send the current version. A stale version overwrites the existing draft. Content includes items, overrides, account_ids, optional tags (up to 20 unique names, 50 characters each), and private notes (up to 20000 characters). Tags and notes are never published.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'id' => $s->string()->required(), 'title' => $s->string()->required(), 'version' => $s->integer()->required(), 'content' => $s->object()->description('items and each network overrides list contain {text, media_ids, media_alt?}. Optional media_alt maps attachment UUIDs in that item to image descriptions of up to 1000 characters. Descriptions belong to this post version, not the media library. Include account_ids.')->required(), 'restore_scheduled' => $s->boolean()->description('Restore a deleted draft returned by workspace with restore_scheduled=true before editing it.')];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Workspace::class)->save($r->all(), true));
    }
}
