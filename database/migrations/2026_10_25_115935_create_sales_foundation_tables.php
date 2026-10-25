<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('currency', 3);
            $table->string('status')->default('draft');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });
        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->string('sku', 100);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->unsignedInteger('cancelled_quantity')->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('warehouse_location_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('remaining_quantity');
            $table->string('warehouse_name');
            $table->string('location_name');
            $table->timestamps();
            $table->index(['inventory_item_id', 'warehouse_location_id']);
        });
        Schema::create('sales_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained();
            $table->foreignId('stock_reservation_id')->constrained();
            $table->foreignId('stock_movement_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('warehouse_location_id')->constrained();
            $table->string('warehouse_name');
            $table->string('location_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['sales_order_id', 'idempotency_key']);
        });
        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained();
            $table->foreignId('sales_fulfillment_id')->constrained();
            $table->foreignId('stock_movement_id')->nullable()->constrained();
            $table->unsignedInteger('quantity');
            $table->string('condition');
            $table->boolean('accepted');
            $table->text('reason');
            $table->decimal('refundable_amount', 14, 2);
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['sales_order_id', 'idempotency_key']);
        });
        Schema::create('sales_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->string('status');
            $table->string('reference')->nullable();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['sales_order_id', 'idempotency_key']);
        });
        Schema::create('sales_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_order_events');
        Schema::dropIfExists('sales_refunds');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sales_fulfillments');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
