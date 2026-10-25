<?php

namespace Database\Factories;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => null,
            'customer_address' => null,
            'currency' => 'PHP',
            'status' => SalesOrderStatus::Draft,
            'total_amount' => '0.00',
            'cancellation_reason' => null,
        ];
    }
}
