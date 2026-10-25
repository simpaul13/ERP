<?php

namespace Database\Seeders;

use App\Models\SalesFulfillment;
use Illuminate\Database\Seeder;

class SalesFulfillmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SalesFulfillment::factory()->create();
    }
}
