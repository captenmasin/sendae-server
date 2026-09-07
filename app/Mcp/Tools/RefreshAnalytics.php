<?php

namespace App\Mcp\Tools;

use App\Models\Publication;
use App\Services\Publisher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RefreshAnalytics extends Tool
{
    protected string $name = 'refresh_analytics';

    protected string $description = 'Manually refresh engagement for a publication through its provider. This can incur API read charges. Cached counts are available without a refresh through workspace. Null means unavailable, not zero.';

    public function schema(JsonSchema $s): array
    {
        return ['id' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $r->validate(['id' => 'required|uuid']);

        return Response::json(app(Publisher::class)->analytics(Publication::findOrFail($r->get('id'))));
    }
}
