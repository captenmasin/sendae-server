<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider');
            $t->string('provider_id');
            $t->string('name');
            $t->string('timezone')->default('UTC');
            $t->json('slots')->nullable();
            $t->text('credentials')->nullable();
            $t->string('status')->default('connected');
            $t->timestamps();
            $t->unique(['provider', 'provider_id']);
        });
        Schema::create('drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title')->default('Untitled draft');
            $t->json('content');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('synced_version')->default(0);
            $t->boolean('dirty')->default(true);
            $t->timestamps();
        });
        Schema::create('media', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('path')->nullable();
            $t->boolean('synced')->default(false);
            $t->timestamps();
        });
        Schema::create('publications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('draft_id')->index();
            $t->uuid('account_id')->index();
            $t->json('snapshot');
            $t->string('status')->default('scheduled')->index();
            $t->timestamp('scheduled_at')->index();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->json('receipts')->nullable();
            $t->text('error')->nullable();
            $t->json('metrics')->nullable();
            $t->string('metrics_status')->default('not_refreshed');
            $t->timestamp('metrics_refreshed_at')->nullable();
            $t->boolean('day30_refreshed')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['publications', 'media', 'drafts', 'accounts', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
