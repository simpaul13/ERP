<?php

namespace Database\Seeders;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReportsOperationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $user = User::query()->where('email', 'e2e@example.com')->first()
            ?? User::query()->where('email', 'e2e@example.test')->first();
        $team = $user?->currentTeam;

        if ($user === null || $team === null) {
            return;
        }

        DB::transaction(function () use ($team, $user): void {
            if (StockMovement::query()->where('team_id', $team->id)->where('reference', 'DEMO-OPS-LOW-ISSUE')->exists()
                || $team->inventoryItems()->where('sku', 'DEMO-OPS-LOW-001')->exists()) {
                return;
            }

            $warehouse = $team->warehouses()->firstOrCreate(
                ['code' => 'MAIN'],
                ['name' => 'Main warehouse', 'is_default' => true],
            );
            $location = $warehouse->defaultLocation();

            $lowStockItem = InventoryItem::factory()->for($team)->create([
                'sku' => 'DEMO-OPS-LOW-001',
                'name' => 'Everyday backpack',
                'category' => 'Bags',
                'quantity' => 0,
                'reorder_point' => 10,
                'unit_cost' => '24.50',
            ]);
            $outOfStockItem = InventoryItem::factory()->for($team)->outOfStock()->create([
                'sku' => 'DEMO-OPS-OUT-001',
                'name' => 'USB-C adapter',
                'category' => 'Accessories',
                'quantity' => 0,
                'unit_cost' => '8.75',
            ]);
            $wellStockedItem = InventoryItem::factory()->for($team)->wellStocked()->create([
                'sku' => 'DEMO-OPS-OK-001',
                'name' => 'Desk organizer',
                'category' => 'Office',
                'quantity' => 0,
                'unit_cost' => '15.00',
            ]);

            $recordStockMovement = app(RecordStockMovement::class);

            $recordStockMovement->handle(
                team: $team,
                inventoryItem: $lowStockItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Opening,
                quantity: 14,
                unitCost: $lowStockItem->unit_cost,
                reference: 'DEMO-OPS-LOW-OPEN',
                user: $user,
            );
            $recordStockMovement->handle(
                team: $team,
                inventoryItem: $lowStockItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Issue,
                quantity: -7,
                unitCost: $lowStockItem->unit_cost,
                reference: 'DEMO-OPS-LOW-ISSUE',
                user: $user,
            );

            $recordStockMovement->handle(
                team: $team,
                inventoryItem: $outOfStockItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Opening,
                quantity: 5,
                unitCost: $outOfStockItem->unit_cost,
                reference: 'DEMO-OPS-OUT-OPEN',
                user: $user,
            );
            $recordStockMovement->handle(
                team: $team,
                inventoryItem: $outOfStockItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Issue,
                quantity: -5,
                unitCost: $outOfStockItem->unit_cost,
                reference: 'DEMO-OPS-OUT-ISSUE',
                user: $user,
            );

            $recordStockMovement->handle(
                team: $team,
                inventoryItem: $wellStockedItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Opening,
                quantity: 32,
                unitCost: $wellStockedItem->unit_cost,
                reference: 'DEMO-OPS-OK-OPEN',
                user: $user,
            );
            $recordStockMovement->handle(
                team: $team,
                inventoryItem: $wellStockedItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Receipt,
                quantity: 8,
                unitCost: $wellStockedItem->unit_cost,
                reference: 'DEMO-OPS-OK-RECEIPT',
                user: $user,
            );

            $supplier = $team->suppliers()->firstOrCreate(
                ['name' => 'Demo Supply Co.'],
                ['contact_name' => 'Morgan Lee', 'is_active' => true],
            );

            SalesOrder::query()->firstOrCreate(
                ['team_id' => $team->id, 'customer_name' => 'Demo Retail Shop'],
                [
                    'created_by' => $user->id,
                    'currency' => 'PHP',
                    'status' => SalesOrderStatus::Draft,
                    'total_amount' => '8490.00',
                ],
            );

            PurchaseOrder::query()->firstOrCreate(
                ['team_id' => $team->id, 'reference' => 'DEMO-OPS-PO-001'],
                [
                    'supplier_id' => $supplier->id,
                    'created_by' => $user->id,
                    'status' => PurchaseOrderStatus::Submitted,
                    'expected_date' => now()->addDays(7)->toDateString(),
                    'total_amount' => '12480.00',
                ],
            );
        });
    }
}
