<?php

namespace Database\Factories;

use App\Models\SalesRefund;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesRefund>
 */
class SalesRefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_return_id' => SalesReturn::factory(),
            'sales_order_id' => fn (array $attributes): int => SalesReturn::query()->whereKey($attributes['sales_return_id'])->firstOrFail()->sales_order_id,
            'amount' => '10.00',
            'status' => 'pending',
            'reference' => null,
            'idempotency_key' => fake()->uuid(),
            'payload_hash' => hash('sha256', 'factory refund'),
        ];
    }
}
