<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $sku
 * @property string $name
 * @property string|null $category
 * @property string|null $location
 * @property int $quantity
 * @property int $reorder_point
 * @property string $unit_cost
 * @property array<int, array{id: string, name: string, src: string, path?: string}>|null $images
 * @property string|null $main_image_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'sku', 'name', 'category', 'location', 'quantity', 'reorder_point', 'unit_cost', 'images', 'main_image_id'])]
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
