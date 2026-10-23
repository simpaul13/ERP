<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $inventory_item_id
 * @property int $warehouse_id
 * @property int|null $warehouse_location_id
 * @property string $item_name
 * @property string $sku
 * @property StockMovementType $type
 * @property int $quantity
 * @property int $quantity_before
 * @property int $quantity_after
 * @property string $unit_cost
 * @property string|null $reference
 * @property string|null $reason
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read InventoryItem|null $inventoryItem
 * @property-read Warehouse $warehouse
 * @property-read WarehouseLocation|null $location
 * @property-read User|null $user
 */
#[Fillable([
    'team_id',
    'inventory_item_id',
    'warehouse_id',
    'warehouse_location_id',
    'item_name',
    'sku',
    'type',
    'quantity',
    'quantity_before',
    'quantity_after',
    'unit_cost',
    'reference',
    'reason',
    'user_id',
])]
class StockMovement extends Model
{
    /**
     * Get the team that owns the movement.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the item the movement belongs to.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Get the warehouse the movement happened in.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the bin the movement happened in.
     *
     * @return BelongsTo<WarehouseLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    /**
     * Get the user who recorded the movement.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }
}
