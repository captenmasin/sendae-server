<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Media extends Model
{
    use BelongsToOwner, HasUuids;

    protected $table = 'media';

    protected $guarded = [];

    protected $hidden = ['user_id', 'path'];

    protected function casts(): array
    {
        return ['synced' => 'boolean'];
    }
}
