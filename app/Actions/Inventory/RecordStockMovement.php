<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordStockMovement
{
    /**
     * Record an immutable stock movement and update the affected balances.
     */
    public function handle(
        Team $team,
        InventoryItem $inventoryItem,
        Warehouse $warehouse,
        WarehouseLocation $location,
        StockMovementType $type,
        int $quantity,
        float $unitCost = 0,
        ?string $reference = null,
        ?string $reason = null,
        ?User $user = null,
    ): StockMovement {
        $this->guard($team, $inventoryItem, $warehouse, $location, $type, $quantity, $reason);

        return DB::transaction(function () use (
            $team,
            $inventoryItem,
            $warehouse,
            $location,
            $type,
            $quantity,
            $unitCost,
            $reference,
            $reason,
            $user,
        ): StockMovement {
            $item = InventoryItem::query()
                ->whereKey($inventoryItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $level = StockLevel::query()->firstOrCreate(
                [
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'warehouse_location_id' => $location->id,
                ],
                [
                    'team_id' => $team->id,
                    'quantity' => 0,
                ],
            );

            $quantityBefore = $level->quantity;
            $quantityAfter = $quantityBefore + $quantity;

            if ($quantityAfter < 0) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Only %d unit(s) are available in %s / %s.',
                        $quantityBefore,
                        $warehouse->name,
                        $location->name,
                    ),
                ]);
            }

            $level->update(['quantity' => $quantityAfter]);

            $item->update([
                'quantity' => $item->quantity + $quantity,
            ]);

            $movement = StockMovement::query()->create([
                'team_id' => $team->id,
                'inventory_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_location_id' => $location->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'unit_cost' => $unitCost,
                'reference' => $reference,
                'reason' => $reason,
                'user_id' => $user?->id,
            ]);

            if ($type !== StockMovementType::Opening) {
                activity('inventory')
                    ->causedBy($user)
                    ->withProperties([
                        'team_id' => $team->id,
                        'item_name' => $item->name,
                        'sku' => $item->sku,
                        'movement_id' => $movement->id,
                        'type' => $type->value,
                        'warehouse' => $warehouse->name,
                        'location' => $location->name,
                        'direction' => $quantity > 0 ? 'in' : 'out',
                        'amount' => abs($quantity),
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'reference' => $reference,
                        'reason' => $reason,
                    ])
                    ->log($this->description($type));
            }

            return $movement;
        });
    }

    /**
     * Validate that the movement belongs to the team and has a usable quantity.
     */
    private function guard(
        Team $team,
        InventoryItem $inventoryItem,
        Warehouse $warehouse,
        WarehouseLocation $location,
        StockMovementType $type,
        int $quantity,
        ?string $reason,
    ): void {
        if ($quantity === 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter a quantity greater than zero.',
            ]);
        }

        if ($inventoryItem->team_id !== $team->id) {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'The selected item does not belong to this team.',
            ]);
        }

        if ($warehouse->team_id !== $team->id) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'The selected warehouse does not belong to this team.',
            ]);
        }

        if ($location->warehouse_id !== $warehouse->id) {
            throw ValidationException::withMessages([
                'warehouse_location_id' => 'The selected bin does not belong to the chosen warehouse.',
            ]);
        }

        if ($type->requiresReason() && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for stock adjustments.',
            ]);
        }

        if (in_array($type, [StockMovementType::Opening, StockMovementType::Receipt], true) && $quantity < 0) {
            throw ValidationException::withMessages([
                'amount' => 'This movement type cannot reduce stock.',
            ]);
        }

        if ($type === StockMovementType::Issue && $quantity > 0) {
            throw ValidationException::withMessages([
                'amount' => 'This movement type cannot increase stock.',
            ]);
        }
    }

    /**
     * Get the activity description for the movement type.
     */
    private function description(StockMovementType $type): string
    {
        return match ($type) {
            StockMovementType::Opening => 'Inventory Opening Balance',
            StockMovementType::Receipt => 'Inventory Stock Received',
            StockMovementType::Issue => 'Inventory Stock Issued',
            StockMovementType::Adjustment => 'Inventory Stock Adjusted',
        };
    }
}
