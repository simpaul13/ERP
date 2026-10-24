<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'inventory_item_id' => null,
            'item_name' => fake()->words(2, true),
            'sku' => fake()->unique()->bothify('SKU-####'),
            'quantity' => fake()->numberBetween(1, 50),
            'received_quantity' => 0,
            'unit_cost' => fake()->randomFloat(2, 1, 500),
            'line_total' => '0.00',
        ];
    }
}
