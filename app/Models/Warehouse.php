<?php

namespace App\Models;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $team_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read Collection<int, WarehouseLocation> $locations
 * @property-read Collection<int, StockLevel> $stockLevels
 * @property-read Collection<int, StockMovement> $stockMovements
 */
#[Fillable(['team_id', 'code', 'name', 'address', 'is_default'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory, SoftDeletes;

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($this->stockLevels()->where('quantity', '>', 0)->exists()
                || StockReservation::query()->where('warehouse_id', $this->id)->where('remaining_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['warehouse' => 'Move all stock and release active sales reservations before archiving this warehouse.']);
            }

            return parent::delete();
        });
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (Warehouse $warehouse): void {
            if (! static::query()->where('team_id', $warehouse->team_id)->exists()) {
                $warehouse->is_default = true;
            }
        });

        static::saving(function (Warehouse $warehouse): void {
            if ($warehouse->is_default) {
                static::query()
                    ->where('team_id', $warehouse->team_id)
                    ->whereKeyNot($warehouse->getKey())
                    ->update(['is_default' => false]);
            }
        });

        static::created(function (Warehouse $warehouse): void {
            if ($warehouse->locations()->exists()) {
                return;
            }

            $warehouse->locations()->create([
                'code' => 'DEFAULT',
                'name' => 'Default bin',
                'is_default' => true,
            ]);
        });
    }

    /**
     * Get the team that owns the warehouse.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the bins that belong to the warehouse.
     *
     * @return HasMany<WarehouseLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    /**
     * Get the stock levels held in the warehouse.
     *
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Get the stock movements recorded against the warehouse.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Get the default bin for the warehouse, creating one if needed.
     */
    public function defaultLocation(): WarehouseLocation
    {
        return $this->locations()->where('is_default', true)->first()
            ?? $this->locations()->firstOrCreate(
                ['is_default' => true],
                ['code' => 'DEFAULT', 'name' => 'Default bin'],
            );
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
