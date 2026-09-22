<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Models\Publication;
use App\Services\Publisher;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class RefreshAnalytics extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'refresh_analytics';

    protected string $description = 'Manually refresh engagement for a publication through its provider. This can incur API read charges. Cached counts are available without a refresh through workspace. Null means unavailable, not zero.';

    public function schema(JsonSchema $s): array
    {
        return ['workspace_id' => $s->string()->description('Id from a workspace call with no arguments.')->required(), 'id' => $s->string()->required()];
    }

    public function handle(Request $r): Response
    {
        $this->selectWorkspace($r);
        $r->validate(['id' => 'required|uuid']);

        return Response::json(app(Publisher::class)->analytics(Publication::findOrFail($r->get('id'))));
    }
}
