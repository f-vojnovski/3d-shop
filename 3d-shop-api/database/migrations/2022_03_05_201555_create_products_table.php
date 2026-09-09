<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Sized to match ProductController's validation rules.
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2);
            $table->string('obj_file_path')->nullable();
            $table->string('gltf_file_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
