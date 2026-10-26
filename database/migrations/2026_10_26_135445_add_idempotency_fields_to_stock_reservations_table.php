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
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unique(['sales_order_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropUnique(['sales_order_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'payload_hash']);
        });
    }
};
