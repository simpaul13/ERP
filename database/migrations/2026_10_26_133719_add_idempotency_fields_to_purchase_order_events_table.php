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
        Schema::table('purchase_order_events', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unique(['purchase_order_id', 'idempotency_key'], 'po_events_order_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_events', function (Blueprint $table): void {
            $table->dropUnique('po_events_order_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'payload_hash']);
        });
    }
};
