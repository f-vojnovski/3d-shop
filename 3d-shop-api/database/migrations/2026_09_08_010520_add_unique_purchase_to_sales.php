<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUniquePurchaseToSales extends Migration
{
    /**
     * Run the migrations.
     *
     * SalesController rejects a repeat purchase in application code. This makes
     * the database enforce it too, so two concurrent requests cannot both pass
     * that check and record the same sale twice.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unique(['buyer_id', 'product_id'], 'sales_buyer_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_buyer_product_unique');
        });
    }
}
