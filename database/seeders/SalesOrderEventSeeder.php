<?php

namespace Database\Seeders;

use App\Models\SalesOrderEvent;
use Illuminate\Database\Seeder;

class SalesOrderEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SalesOrderEvent::factory()->create();
    }
}
