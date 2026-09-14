<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily quota counts rows here, and the hourly pruner deleted them after two
 * hours: twelve fresh quotas a day. Pruning now frees the picture and keeps the
 * record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_views', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('custom_views', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropSoftDeletes();
        });
    }
};
