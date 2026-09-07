<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToOwner, HasUuids;

    protected $guarded = [];

    protected $hidden = ['user_id', 'credentials'];

    protected function casts(): array
    {
        return ['slots' => 'array', 'credentials' => 'encrypted:array'];
    }
}
