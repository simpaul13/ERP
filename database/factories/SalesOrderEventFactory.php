<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderEvent>
 */
class SalesOrderEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'user_id' => null,
            'from_status' => null,
            'to_status' => 'draft',
            'note' => 'Sales order created.',
        ];
    }
}
