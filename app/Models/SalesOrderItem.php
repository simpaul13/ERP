<?php

namespace App\Models;

use Database\Factories\SalesOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int|null $inventory_item_id
 * @property string $item_name
 * @property string $sku
 * @property int $quantity
 * @property int $fulfilled_quantity
 * @property int $cancelled_quantity
 * @property string $unit_price
 * @property string $line_total
 */
#[Fillable(['sales_order_id', 'inventory_item_id', 'item_name', 'sku', 'quantity', 'fulfilled_quantity', 'cancelled_quantity', 'unit_price', 'line_total'])]
class SalesOrderItem extends Model
{
    /** @use HasFactory<SalesOrderItemFactory> */
    use HasFactory;

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** @return HasMany<SalesReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'fulfilled_quantity' => 'integer', 'cancelled_quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
