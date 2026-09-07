<?php

namespace App\Mcp\Tools;

use App\Services\Attachments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AttachMedia extends Tool
{
    protected string $name = 'attach_media';

    protected string $description = 'Upload a JPEG, PNG, WebP, MP4 or MOV attachment (max 100 MB). Return its media ID, then include it in a draft item media_ids using save_draft. For large files prefer authenticated POST /api/media multipart upload.';

    public function schema(JsonSchema $s): array
    {
        return ['name' => $s->string()->required(), 'base64' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        return Response::json(app(Attachments::class)->base64($r->all()));
    }
}
