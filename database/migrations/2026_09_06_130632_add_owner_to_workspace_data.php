<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('workspace_id', 64)->nullable()->unique();
        });
        $owner = DB::table('users')->min('id');
        foreach (DB::table('users')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update(['workspace_id' => hash('sha256', config('app.key').($user->id === $owner ? '' : '|'.$user->id))]);
        }
        foreach (['accounts', 'drafts', 'media', 'publications'] as $name) {
            if (! $owner && DB::table($name)->exists()) {
                throw new RuntimeException('Create the existing workspace owner before migrating its data.');
            }
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            });
            DB::table($name)->update(['user_id' => $owner]);
        }
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Workspace isolation cannot be rolled back safely after registration. Restore a backup instead.');
    }
};
