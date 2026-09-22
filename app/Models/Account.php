<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

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
