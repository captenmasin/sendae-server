<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('icon', 32)->default('◻');
            $table->timestamps();
        });
        foreach (DB::table('users')->get() as $user) {
            DB::table('workspaces')->insert(['id' => $user->workspace_id, 'user_id' => $user->id, 'name' => 'Personal', 'icon' => '◻', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['accounts', 'drafts', 'media', 'publications'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('workspace_id', 64)->nullable()->index();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            });
            foreach (DB::table('users')->get() as $user) {
                DB::table($name)->where('user_id', $user->id)->update(['workspace_id' => $user->workspace_id]);
            }
        }
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider', 'provider_id']);
            $table->unique(['workspace_id', 'provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a backup to roll back multiple workspaces without merging private data.');
    }
};
