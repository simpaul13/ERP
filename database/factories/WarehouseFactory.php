<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
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
            'code' => Str::upper(fake()->unique()->bothify('WH-###')),
            'name' => fake()->company().' Warehouse',
            'address' => fake()->address(),
            'is_default' => false,
        ];
    }
}
