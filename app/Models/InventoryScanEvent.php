<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $transaction_id
 * @property string $scan_id
 * @property string $workflow
 * @property int|null $inventory_item_id
 * @property int $warehouse_id
 * @property int|null $warehouse_location_id
 * @property string $item_code
 * @property string $bin_code
 * @property int $quantity
 * @property int|null $stock_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'team_id',
    'user_id',
    'transaction_id',
    'scan_id',
    'workflow',
    'inventory_item_id',
    'warehouse_id',
    'warehouse_location_id',
    'item_code',
    'bin_code',
    'quantity',
    'stock_movement_id',
])]
class InventoryScanEvent extends Model
{
    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
