<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $inventory_item_id
 * @property string $item_name
 * @property string $sku
 * @property int $quantity
 * @property int $received_quantity
 * @property string $unit_cost
 * @property string $line_total
 */
#[Fillable(['purchase_order_id', 'inventory_item_id', 'item_name', 'sku', 'quantity', 'received_quantity', 'unit_cost', 'line_total'])]
class PurchaseOrderItem extends Model
{
    use HasFactory;

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'received_quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }
}
