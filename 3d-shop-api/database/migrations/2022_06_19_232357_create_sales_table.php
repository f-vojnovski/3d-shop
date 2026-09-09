<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('product_id')->constrained('products');
            // Copied at purchase time so repricing cannot rewrite past sales.
            $table->decimal('price', 8, 2);
            $table->timestamps();

            $table->unique(['buyer_id', 'product_id'], 'sales_buyer_product_unique');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
