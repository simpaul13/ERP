<?php

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WarehouseLocation>
 */
class WarehouseLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'code' => Str::upper(fake()->unique()->bothify('BIN-###')),
            'name' => 'Bin '.fake()->bothify('A-##'),
            'is_default' => false,
        ];
    }
}
