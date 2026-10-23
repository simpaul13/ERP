<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        foreach (DB::table('teams')->whereNull('deleted_at')->get() as $team) {
            $warehouseId = DB::table('warehouses')->insertGetId([
                'team_id' => $team->id,
                'code' => 'MAIN',
                'name' => 'Main warehouse',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $defaultLocationId = DB::table('warehouse_locations')->insertGetId([
                'warehouse_id' => $warehouseId,
                'code' => 'DEFAULT',
                'name' => 'Default bin',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $locations = [];

            foreach (DB::table('inventory_items')->where('team_id', $team->id)->get() as $item) {
                $locationName = trim((string) ($item->location ?? ''));
                $locationId = $defaultLocationId;

                if ($locationName !== '') {
                    $locationKey = Str::lower($locationName);

                    if (! isset($locations[$locationKey])) {
                        $baseCode = Str::limit(Str::upper(Str::slug($locationName, '-')), 45, '') ?: 'BIN';
                        $code = $baseCode;
                        $suffix = 2;

                        while (DB::table('warehouse_locations')
                            ->where('warehouse_id', $warehouseId)
                            ->where('code', $code)
                            ->exists()) {
                            $code = $baseCode.'-'.$suffix;
                            $suffix++;
                        }

                        $locations[$locationKey] = DB::table('warehouse_locations')->insertGetId([
                            'warehouse_id' => $warehouseId,
                            'code' => $code,
                            'name' => $locationName,
                            'is_default' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $locationId = $locations[$locationKey];
                }

                $quantity = (int) $item->quantity;
                $occurredAt = $item->created_at ?? $now;

                DB::table('stock_levels')->insert([
                    'team_id' => $team->id,
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $warehouseId,
                    'warehouse_location_id' => $locationId,
                    'quantity' => $quantity,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]);

                if ($quantity <= 0) {
                    continue;
                }

                DB::table('stock_movements')->insert([
                    'team_id' => $team->id,
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $warehouseId,
                    'warehouse_location_id' => $locationId,
                    'item_name' => $item->name,
                    'sku' => $item->sku,
                    'type' => 'opening',
                    'quantity' => $quantity,
                    'quantity_before' => 0,
                    'quantity_after' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'reference' => null,
                    'reason' => 'Migrated opening balance',
                    'user_id' => null,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]);
            }
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('location')->nullable()->after('category');
        });
    }
};
