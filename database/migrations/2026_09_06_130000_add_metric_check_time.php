<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', fn (Blueprint $t) => $t->timestamp('metrics_checked_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('publications', fn (Blueprint $t) => $t->dropColumn('metrics_checked_at'));
    }
};
