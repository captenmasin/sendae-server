<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Publication extends Model
{
    use BelongsToOwner, HasUuids;

    protected $hidden = ['user_id', 'workspace_id'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'receipts' => 'array', 'metrics' => 'array', 'scheduled_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'metrics_refreshed_at' => 'immutable_datetime', 'metrics_checked_at' => 'immutable_datetime', 'day30_refreshed' => 'boolean'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
