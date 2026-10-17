<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use App\Services\Workspace;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\SelectsWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SavePostingSlots extends Tool
{
    use SelectsWorkspace;

    protected string $name = 'save_posting_slots';

    protected string $description = 'Replace an account’s weekly posting slots and timezone. Read workspace first for the account id and current slots. Days are 0 (Sunday) through 6 (Saturday), times are HH:MM in the supplied IANA timezone and follow daylight saving changes. Pass an empty slots array to clear all slots. Existing scheduled publications keep their times; queue mode uses the updated slots.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('Id from a workspace call with no arguments.')->required(),
            'id' => $schema->string()->description('Connected account id from workspace.')->required(),
            'timezone' => $schema->string()->description('IANA timezone, for example Europe/London.')->required(),
            'slots' => $schema->array()->items($schema->object([
                'day' => $schema->integer()->min(0)->max(6)->required(),
                'time' => $schema->string()->description('Local time in 24-hour HH:MM format.')->required(),
            ]))->max(100)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->selectWorkspace($request);

        return Response::json(app(Workspace::class)->savePostingSlots($request->all())->toArray());
    }
}
