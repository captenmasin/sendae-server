<?php

namespace App\Mcp\Tools;

use App\Services\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CancelPublication extends Tool
{
    protected string $name = 'cancel_publication';

    protected string $description = 'Cancel an unfinished publication by ID. In-flight, uncertain and published posts cannot be cancelled. This does not delete a live social post.';

    public function schema(JsonSchema $s): array
    {
        return ['id' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $r->validate(['id' => 'required|uuid']);

        return Response::json(app(Workspace::class)->cancel($r->get('id')));
    }
}
