<?php

namespace Database\Seeders;

use App\Models\StockReservation;
use Illuminate\Database\Seeder;

class StockReservationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StockReservation::factory()->create();
    }
}
