<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

class WorkspaceOwner
{
    private ?int $userId = null;

    private ?string $workspaceId = null;

    public function id(): ?int
    {
        return $this->userId ?? Auth::id();
    }

    public function requireId(): int
    {
        return $this->id() ?? throw new AuthenticationException;
    }

    public function workspaceId(): ?string
    {
        if (! $this->id()) {
            return null;
        }
        $id = $this->workspaceId ?? request()->header('X-Workspace-Id') ?? User::find($this->id())?->workspace_id;
        abort_unless(is_string($id) && Workspace::where('user_id', $this->id())->whereKey($id)->exists(), 404);

        return $id;
    }

    public function select(string $id): void
    {
        abort_unless(Workspace::where('user_id', $this->requireId())->whereKey($id)->exists(), 404);
        $this->workspaceId = $id;
    }

    public function run(int $userId, callable $operation, ?string $workspaceId = null): mixed
    {
        $previous = $this->userId;
        $previousWorkspace = $this->workspaceId;
        $this->userId = $userId;
        $this->workspaceId = $workspaceId ?? User::findOrFail($userId)->workspace_id;
        try {
            return $operation();
        } finally {
            $this->userId = $previous;
            $this->workspaceId = $previousWorkspace;
        }
    }
}
