<?php

namespace App\Models;

use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $sales_order_item_id
 * @property int|null $inventory_item_id
 * @property int $warehouse_id
 * @property int $warehouse_location_id
 * @property int $quantity
 * @property int $remaining_quantity
 * @property string $warehouse_name
 * @property string $location_name
 * @property string|null $idempotency_key
 * @property string|null $payload_hash
 * @property-read SalesOrderItem $salesOrderItem
 */
#[Fillable(['sales_order_id', 'sales_order_item_id', 'inventory_item_id', 'warehouse_id', 'warehouse_location_id', 'quantity', 'remaining_quantity', 'warehouse_name', 'location_name', 'idempotency_key', 'payload_hash'])]
class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'remaining_quantity' => 'integer'];
    }
}
