<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $sku
 * @property string $name
 * @property string|null $category
 * @property int $quantity
 * @property int $reorder_point
 * @property string $unit_cost
 * @property array<int, array{id: string, name: string, src: string, path?: string}>|null $images
 * @property string|null $main_image_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, StockLevel> $stockLevels
 * @property-read Collection<int, StockMovement> $stockMovements
 */
#[Fillable(['team_id', 'sku', 'name', 'category', 'quantity', 'reorder_point', 'unit_cost', 'images', 'main_image_id'])]
class InventoryItem extends Model
{
    /**
     * Get the team that owns the inventory item.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the per-warehouse stock levels for the item.
     *
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Get the stock movement history for the item.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Recalculate the cached quantity from the stock levels.
     */
    public function syncQuantityFromStockLevels(): void
    {
        $this->forceFill([
            'quantity' => (int) $this->stockLevels()->sum('quantity'),
        ])->saveQuietly();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reorder_point' => 'integer',
            'unit_cost' => 'decimal:2',
            'images' => 'array',
        ];
    }
}
