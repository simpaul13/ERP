<?php

namespace Database\Seeders;

use App\Models\SalesOrderItem;
use Illuminate\Database\Seeder;

class SalesOrderItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SalesOrderItem::factory()->create();
    }
}
