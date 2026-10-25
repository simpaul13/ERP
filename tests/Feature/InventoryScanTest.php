<?php

use App\Enums\StockMovementType;
use App\Models\InventoryScanEvent;
use App\Models\StockMovement;
use App\Models\User;

function scanPayload(array $overrides = []): array
{
    return [
        'workflow' => 'receiving',
        'transaction_id' => fake()->uuid(),
        'scan_id' => fake()->uuid(),
        'item_code' => 'BX-2048',
        'bin_code' => 'DEFAULT',
        'warehouse_id' => 1,
        'quantity' => 3,
        ...$overrides,
    ];
}

test('warehouse receiving and picking scans record user-attributed stock movements', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $bin = $warehouse->defaultLocation();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 2.5,
    ]);
    $url = route('inventory-scans.store', ['current_team' => $team->slug]);
    $payload = scanPayload(['warehouse_id' => $warehouse->id]);

    $this->actingAs($user)->postJson($url, $payload)
        ->assertOk()
        ->assertJsonPath('workflow', 'receiving')
        ->assertJsonPath('quantityAfter', 3);

    $this->actingAs($user)->postJson($url, scanPayload([
        'workflow' => 'picking',
        'transaction_id' => $payload['transaction_id'],
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
    ]))->assertOk()->assertJsonPath('quantityAfter', 2);

    expect($item->refresh()->quantity)->toBe(2)
        ->and($item->stockMovements()->latest('id')->first()->type)->toBe(StockMovementType::Issue)
        ->and($item->stockMovements()->latest('id')->first()->user_id)->toBe($user->id)
        ->and($item->stockMovements()->latest('id')->first()->reference)->toBe('SCAN:'.$payload['transaction_id']);

    $this->assertDatabaseHas('inventory_scan_events', [
        'team_id' => $team->id,
        'user_id' => $user->id,
        'inventory_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_location_id' => $bin->id,
        'transaction_id' => $payload['transaction_id'],
    ]);
});

test('cycle counts record the difference and allow a zero-count bin', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $bin = $warehouse->defaultLocation();
    $item = createInventoryItem($team, ['sku' => 'BX-2048', 'name' => 'Shipping carton'], 4, $warehouse, $bin, $user);
    $url = route('inventory-scans.store', ['current_team' => $team->slug]);

    $this->actingAs($user)->postJson($url, scanPayload([
        'workflow' => 'cycle_count',
        'warehouse_id' => $warehouse->id,
        'quantity' => 0,
    ]))->assertOk()->assertJsonPath('quantityAfter', 0);

    $movement = $item->stockMovements()->latest('id')->firstOrFail();
    expect($item->refresh()->quantity)->toBe(0)
        ->and($movement->type)->toBe(StockMovementType::Adjustment)
        ->and($movement->quantity)->toBe(-4)
        ->and($movement->user_id)->toBe($user->id);
});

test('unknown item and mismatched bin scans return clear validation feedback', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    createInventoryItem($team, ['sku' => 'BX-2048', 'name' => 'Shipping carton']);
    $url = route('inventory-scans.store', ['current_team' => $team->slug]);

    $this->actingAs($user)->postJson($url, scanPayload([
        'warehouse_id' => $warehouse->id,
        'item_code' => 'NOT-REAL',
    ]))->assertUnprocessable()->assertJsonValidationErrors('item_code')
        ->assertJsonPath('errors.item_code.0', 'Unknown item barcode or SKU: NOT-REAL.');

    $this->actingAs($user)->postJson($url, scanPayload([
        'warehouse_id' => $warehouse->id,
        'bin_code' => 'NO-SUCH-BIN',
    ]))->assertUnprocessable()->assertJsonValidationErrors('bin_code');

    expect(InventoryScanEvent::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('replaying the same scan ID does not duplicate the stock movement', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $item = createInventoryItem($team, ['sku' => 'BX-2048', 'name' => 'Shipping carton']);
    $url = route('inventory-scans.store', ['current_team' => $team->slug]);
    $payload = scanPayload(['warehouse_id' => $warehouse->id]);

    $this->actingAs($user)->postJson($url, $payload)->assertOk()->assertJsonPath('duplicate', false);
    $this->actingAs($user)->postJson($url, $payload)->assertOk()->assertJsonPath('duplicate', true);

    expect($item->refresh()->quantity)->toBe(3)
        ->and($item->stockMovements()->count())->toBe(1)
        ->and(InventoryScanEvent::query()->count())->toBe(1);
});

test('scan events are bound to the current user and cannot be replayed with different data', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    createInventoryItem($team, ['sku' => 'BX-2048', 'name' => 'Shipping carton']);
    $url = route('inventory-scans.store', ['current_team' => $team->slug]);
    $payload = scanPayload(['warehouse_id' => $warehouse->id]);

    $this->actingAs($user)->postJson($url, $payload)->assertOk();
    $this->actingAs($user)->postJson($url, scanPayload([
        ...$payload,
        'item_code' => 'DIFFERENT',
    ]))->assertConflict();

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)->postJson($url, $payload)->assertForbidden();
});
test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
