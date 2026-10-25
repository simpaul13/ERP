<?php

namespace Database\Factories;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (StockReservation $reservation): void {
            if ($reservation->remaining_quantity === 0) {
                return;
            }
            $line = $reservation->salesOrderItem;
            $item = $line->inventoryItem;
            $warehouse = Warehouse::query()->findOrFail($reservation->warehouse_id);
            app(RecordStockMovement::class)->handle(
                team: $reservation->salesOrder->team, inventoryItem: $item,
                warehouse: $warehouse, location: $warehouse->locations()->findOrFail($reservation->warehouse_location_id),
                type: StockMovementType::Opening, quantity: $reservation->remaining_quantity,
                unitCost: $item->unit_cost,
            );
            $reservation->salesOrder->update(['status' => SalesOrderStatus::Reserved]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_order_item_id' => SalesOrderItem::factory(),
            'sales_order_id' => fn (array $attributes): int => SalesOrderItem::query()->whereKey($attributes['sales_order_item_id'])->firstOrFail()->sales_order_id,
            'inventory_item_id' => function (array $attributes): int {
                $line = SalesOrderItem::query()->whereKey($attributes['sales_order_item_id'])->firstOrFail();
                if ($line->inventory_item_id !== null) {
                    return $line->inventory_item_id;
                }
                $item = InventoryItem::query()->create([
                    'team_id' => $line->salesOrder->team_id, 'name' => $line->item_name, 'sku' => $line->sku,
                    'quantity' => 0, 'reorder_point' => 0, 'unit_cost' => '4.00',
                ]);
                $line->update(['inventory_item_id' => $item->id]);

                return $item->id;
            },
            'warehouse_id' => fn (array $attributes): int => SalesOrder::query()->whereKey($attributes['sales_order_id'])->firstOrFail()->team->warehouses()->firstOrFail()->id,
            'warehouse_location_id' => fn (array $attributes): int => Warehouse::query()->whereKey($attributes['warehouse_id'])->firstOrFail()->defaultLocation()->id,
            'warehouse_name' => fn (array $attributes): string => Warehouse::query()->whereKey($attributes['warehouse_id'])->firstOrFail()->name,
            'location_name' => fn (array $attributes): string => WarehouseLocation::query()->whereKey($attributes['warehouse_location_id'])->firstOrFail()->name,
            'quantity' => 1,
            'remaining_quantity' => 1,
        ];
    }
}
