<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sanctum 4 reads and writes expires_at; the table predates it (Sanctum 2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
