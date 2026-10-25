<?php

namespace Database\Seeders;

use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use App\Models\SalesOrderItem;
use Illuminate\Database\Seeder;

class SalesOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $order = SalesOrder::factory()->create();
        SalesOrderItem::factory()->for($order)->create();
        SalesOrderEvent::factory()->for($order)->create(['user_id' => $order->created_by]);
    }
}
