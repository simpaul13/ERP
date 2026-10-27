<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
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
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->words(2, true),
            'category' => fake()->word(),
            'quantity' => fake()->numberBetween(0, 100),
            'reorder_point' => fake()->numberBetween(5, 20),
            'unit_cost' => fake()->randomFloat(2, 1, 500),
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => 4,
            'reorder_point' => 10,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => 0,
            'reorder_point' => 8,
        ]);
    }

    public function wellStocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => 40,
            'reorder_point' => 8,
        ]);
    }
}
