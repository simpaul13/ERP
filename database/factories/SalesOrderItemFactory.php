<?php

namespace Database\Factories;

use App\Actions\Sales\SalesMoney;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderItem>
 */
class SalesOrderItemFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (SalesOrderItem $line): void {
            $total = $line->salesOrder->items()->get()->sum(fn (SalesOrderItem $item): int => SalesMoney::minor($item->line_total));
            $line->salesOrder->update(['total_amount' => SalesMoney::decimal((int) $total)]);
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
            'sales_order_id' => SalesOrder::factory(),
            'inventory_item_id' => null,
            'item_name' => fake()->words(2, true),
            'sku' => fake()->unique()->bothify('SALE-####'),
            'quantity' => 5,
            'fulfilled_quantity' => 0,
            'cancelled_quantity' => 0,
            'unit_price' => '10.00',
            'line_total' => '50.00',
        ];
    }
}
