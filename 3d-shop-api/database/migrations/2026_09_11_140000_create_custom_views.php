<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deliberately not `product_files`: nothing here is attested, and all of it expires. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_file_id')->constrained('product_files')->cascadeOnDelete();
            $table->string('pass', 16);
            $table->string('status', 16);
            $table->json('camera');
            $table->string('fingerprint', 64);
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['product_file_id', 'user_id', 'fingerprint']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_views');
    }
};
