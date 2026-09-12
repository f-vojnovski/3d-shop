<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A view a buyer asked for can be of a moving model rather than a still one.
 * Null is a still, which is every row that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_views', function (Blueprint $table) {
            $table->unsignedSmallInteger('clip')->nullable()->after('pass');
        });
    }

    public function down(): void
    {
        Schema::table('custom_views', function (Blueprint $table) {
            $table->dropColumn('clip');
        });
    }
};
