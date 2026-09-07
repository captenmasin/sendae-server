<?php

namespace App\Models;

use App\Services\WorkspaceOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToOwner
{
    public static function bootBelongsToOwner(): void
    {
        static::addGlobalScope('owner', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('user_id'), app(WorkspaceOwner::class)->id() ?? 0)->where($query->getModel()->qualifyColumn('workspace_id'), app(WorkspaceOwner::class)->workspaceId());
        });
        static::creating(function (Model $model) {
            $model->user_id = app(WorkspaceOwner::class)->requireId();
            $model->workspace_id = app(WorkspaceOwner::class)->workspaceId();
            abort_if($model->id && static::withoutGlobalScope('owner')->whereKey($model->id)->exists(), 404);
        });
    }
}
