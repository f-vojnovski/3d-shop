<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('USD');
            $table->foreignId('user_id')->constrained();
            $table->string('preview_mode')->default('interactive');
            $table->boolean('unlisted')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index(['unlisted', 'id']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('USD');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['buyer_id', 'product_id'], 'sales_buyer_product_unique');
            $table->index('product_id');
        });

        Schema::create('product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['deliverable', 'preview_image', 'thumbnail']);
            $table->string('format')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'kind']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_files');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('products');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
