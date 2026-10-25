<?php

namespace Database\Factories;

use App\Models\SalesFulfillment;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesReturn>
 */
class SalesReturnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_fulfillment_id' => SalesFulfillment::factory(),
            'sales_order_id' => fn (array $attributes): int => SalesFulfillment::query()->whereKey($attributes['sales_fulfillment_id'])->firstOrFail()->sales_order_id,
            'sales_order_item_id' => fn (array $attributes): int => SalesFulfillment::query()->whereKey($attributes['sales_fulfillment_id'])->firstOrFail()->sales_order_item_id,
            'stock_movement_id' => null,
            'quantity' => 1,
            'condition' => 'damaged',
            'accepted' => true,
            'reason' => 'Damaged item received from customer.',
            'refundable_amount' => '10.00',
            'idempotency_key' => fake()->uuid(),
            'payload_hash' => hash('sha256', 'factory return'),
        ];
    }
}
