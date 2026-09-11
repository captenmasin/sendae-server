<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Workspace extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'name', 'icon', 'image'];

    protected $hidden = ['user_id'];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->id ??= hash('sha256', (string) Str::uuid());
        });
    }
}
