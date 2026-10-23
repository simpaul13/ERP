<?php

namespace App\Models;

use Database\Factories\WarehouseLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $warehouse_id
 * @property string $code
 * @property string $name
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, StockLevel> $stockLevels
 */
#[Fillable(['warehouse_id', 'code', 'name', 'is_default'])]
class WarehouseLocation extends Model
{
    /** @use HasFactory<WarehouseLocationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::saving(function (WarehouseLocation $location): void {
            if ($location->is_default) {
                static::query()
                    ->where('warehouse_id', $location->warehouse_id)
                    ->whereKeyNot($location->getKey())
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Get the warehouse that owns the bin.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the stock levels stored in the bin.
     *
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
