<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspaces;
use Laravel\Mcp\Server\Tool;
use App\Services\Attachments;
use App\Services\WorkspaceOwner;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SaveWorkspaceImage extends Tool
{
    protected string $name = 'save_workspace_image';

    protected string $description = 'Set a workspace image from base64 encoded JPEG, PNG or WebP bytes, up to 2 MB. Replaces the existing workspace image.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->required(), 'name' => $s->string()->required(), 'base64' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        app(WorkspaceOwner::class)->requireId();
        $data = $r->validate(['workspace_id' => 'required|string|size:64']);

        return Response::json(app(Attachments::class)->withBase64File($r->all(), fn (UploadedFile $file): array => app(Workspaces::class)->saveImage($data['workspace_id'], $file), 2048));
    }
}
