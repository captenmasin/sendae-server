<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;
use App\Models\Workspace;
use App\Services\WorkspaceOwner;

trait SelectsWorkspace
{
    protected function selectWorkspace(Request $request): void
    {
        $id = $request->validate([
            'workspace_id' => ['required', 'string', 'size:64'],
        ])['workspace_id'];

        abort_unless(
            Workspace::query()->where('user_id', app(WorkspaceOwner::class)->requireId())->whereKey($id)->exists(),
            404,
            'Choose a workspace id returned by the workspace tool.',
        );

        app(WorkspaceOwner::class)->select($id);
    }
}
