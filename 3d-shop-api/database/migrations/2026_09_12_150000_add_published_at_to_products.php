<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `unlisted` alone cannot tell "never published" from "taken off sale", and a
 * seller needs different words and a different button for each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('unlisted');
        });

        // Everything that already exists was live the moment it was created.
        DB::table('products')->where('unlisted', false)->update([
            'published_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
