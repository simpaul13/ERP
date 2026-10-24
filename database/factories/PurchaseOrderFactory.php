<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
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
            'supplier_id' => Supplier::factory(),
            'created_by' => User::factory(),
            'approved_by' => null,
            'status' => PurchaseOrderStatus::Draft,
            'expected_date' => fake()->dateTimeBetween('+1 day', '+2 months')->format('Y-m-d'),
            'reference' => fake()->optional()->bothify('REF-#####'),
            'cancellation_reason' => null,
            'total_amount' => '0.00',
        ];
    }
}
