<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Services\Attachments;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class AttachMedia extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'attach_media';

    protected string $description = 'Upload a JPEG, PNG, WebP, MP4 or MOV attachment (max 100 MB). Return its media ID, then include it in a draft item media_ids using save_draft. For large files prefer authenticated POST /api/media multipart upload.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'name' => $s->string()->required(), 'base64' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);

        return Response::json(app(Attachments::class)->base64($r->all()));
    }
}
