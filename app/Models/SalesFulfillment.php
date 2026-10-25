<?php

namespace App\Models;

use Database\Factories\SalesFulfillmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $sales_order_item_id
 * @property int $stock_reservation_id
 * @property int $stock_movement_id
 * @property int $quantity
 * @property string $unit_cost
 * @property string $warehouse_name
 * @property string $location_name
 * @property string $payload_hash
 * @property-read SalesOrderItem $salesOrderItem
 */
#[Fillable(['sales_order_id', 'sales_order_item_id', 'stock_reservation_id', 'stock_movement_id', 'warehouse_id', 'warehouse_location_id', 'warehouse_name', 'location_name', 'quantity', 'unit_cost', 'idempotency_key', 'payload_hash'])]
class SalesFulfillment extends Model
{
    /** @use HasFactory<SalesFulfillmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (SalesFulfillment $fulfillment): void {
            throw new \LogicException('Sales fulfillments are immutable.');
        });
        static::deleting(function (SalesFulfillment $fulfillment): void {
            throw new \LogicException('Sales fulfillments are immutable.');
        });
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /** @return HasMany<SalesReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost' => 'decimal:2'];
    }
}
