<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order is the priced snapshot of a cart. What the buyer is charged comes
 * from here, never from the request, and `sales` stays the entitlement record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users');
            $table->string('status')->default('pending');
            $table->char('currency', 3)->default('USD');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('commission_cents');
            $table->string('gateway')->default('stripe');
            $table->string('session_id')->nullable()->unique();
            $table->string('payment_intent_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['buyer_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('seller_id')->constrained('users');
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('USD');
            $table->unsignedBigInteger('commission_cents');
            $table->timestamps();

            // One product per order: a second copy is not a second licence.
            $table->unique(['order_id', 'product_id']);
        });

        // Stripe retries, so the event id is the primary key: a replay is a
        // duplicate insert rather than a second fulfilment.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->string('gateway')->default('stripe');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('handled_at')->nullable();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('order_item_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
        });

        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
