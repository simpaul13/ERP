<?php

namespace Database\Seeders;

use App\Models\SalesRefund;
use Illuminate\Database\Seeder;

class SalesRefundSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SalesRefund::factory()->create();
    }
}
