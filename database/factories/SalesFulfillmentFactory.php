<?php

namespace Database\Factories;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\SalesFulfillment;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesFulfillment>
 */
class SalesFulfillmentFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (SalesFulfillment $fulfillment): void {
            $line = $fulfillment->salesOrderItem;
            $line->increment('fulfilled_quantity', $fulfillment->quantity);
            $order = $fulfillment->salesOrder;
            $complete = ! $order->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->exists();
            $order->update(['status' => $complete ? SalesOrderStatus::Fulfilled : SalesOrderStatus::PartiallyFulfilled]);
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
            'stock_reservation_id' => StockReservation::factory()->state(['remaining_quantity' => 0]),
            'sales_order_id' => fn (array $attributes): int => StockReservation::query()->whereKey($attributes['stock_reservation_id'])->firstOrFail()->sales_order_id,
            'sales_order_item_id' => fn (array $attributes): int => StockReservation::query()->whereKey($attributes['stock_reservation_id'])->firstOrFail()->sales_order_item_id,
            'warehouse_id' => fn (array $attributes): int => StockReservation::query()->whereKey($attributes['stock_reservation_id'])->firstOrFail()->warehouse_id,
            'warehouse_location_id' => fn (array $attributes): int => StockReservation::query()->whereKey($attributes['stock_reservation_id'])->firstOrFail()->warehouse_location_id,
            'warehouse_name' => fn (array $attributes): string => StockReservation::query()->whereKey($attributes['stock_reservation_id'])->firstOrFail()->warehouse_name,
            'location_name' => fn (array $attributes): string => StockReservation::query()->whereKey($attributes['stock_reservation_id'])->firstOrFail()->location_name,
            'stock_movement_id' => function (array $attributes): int {
                $reservation = StockReservation::query()->whereKey($attributes['stock_reservation_id'])->firstOrFail();

                $item = $reservation->salesOrderItem->inventoryItem;
                $warehouse = Warehouse::query()->findOrFail($reservation->warehouse_id);
                $location = $warehouse->locations()->findOrFail($reservation->warehouse_location_id);
                $action = app(RecordStockMovement::class);
                $quantity = (int) $attributes['quantity'];
                $action->handle(team: $reservation->salesOrder->team, inventoryItem: $item, warehouse: $warehouse,
                    location: $location, type: StockMovementType::Opening, quantity: $quantity, unitCost: '4.00');

                return $action->handle(team: $reservation->salesOrder->team, inventoryItem: $item, warehouse: $warehouse,
                    location: $location, type: StockMovementType::Issue, quantity: -$quantity, unitCost: '4.00')->id;
            },
            'quantity' => 1,
            'unit_cost' => '4.00',
            'idempotency_key' => fake()->uuid(),
            'payload_hash' => hash('sha256', 'factory fulfillment'),
        ];
    }
}
