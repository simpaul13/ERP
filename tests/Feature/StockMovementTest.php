<?php

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('an opening balance writes a movement, a stock level, and the cached quantity', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 2.50,
    ], 12, user: $user);

    $warehouse = $team->defaultWarehouse();
    $movement = $item->stockMovements()->sole();

    expect($item->quantity)->toBe(12)
        ->and($item->stockLevels()->sum('quantity'))->toBe(12)
        ->and($movement->type)->toBe(StockMovementType::Opening)
        ->and($movement->quantity)->toBe(12)
        ->and($movement->quantity_before)->toBe(0)
        ->and($movement->quantity_after)->toBe(12)
        ->and($movement->warehouse_id)->toBe($warehouse->id)
        ->and($movement->user_id)->toBe($user->id)
        ->and($movement->item_name)->toBe('Shipping carton')
        ->and($movement->sku)->toBe('BX-2048');
});

test('movements update the selected bin and the cached item total', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $binA = $warehouse->defaultLocation();
    $binB = $warehouse->locations()->create(['code' => 'B-01', 'name' => 'Aisle B']);
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 1,
    ], 10, $warehouse, $binA);
    $action = app(RecordStockMovement::class);

    $action->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $binB,
        type: StockMovementType::Receipt,
        quantity: 5,
        unitCost: 1,
        reference: 'PO-1001',
        reason: 'Delivery',
        user: $user,
    );

    $action->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $binA,
        type: StockMovementType::Adjustment,
        quantity: -3,
        unitCost: 1,
        reason: 'Damaged in storage',
        user: $user,
    );

    $item->refresh();

    expect($item->quantity)->toBe(12)
        ->and($item->stockLevels()->where('warehouse_location_id', $binA->id)->value('quantity'))->toBe(7)
        ->and($item->stockLevels()->where('warehouse_location_id', $binB->id)->value('quantity'))->toBe(5)
        ->and($item->stockLevels()->sum('quantity'))->toBe(12)
        ->and($item->stockMovements()->count())->toBe(3);
});

test('stock cannot be removed from a bin that does not hold it', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $binA = $warehouse->defaultLocation();
    $binB = $warehouse->locations()->create(['code' => 'B-01', 'name' => 'Aisle B']);
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 1,
    ], 5, $warehouse, $binA);

    expect(fn () => app(RecordStockMovement::class)->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $binB,
        type: StockMovementType::Adjustment,
        quantity: -1,
        reason: 'Recount',
        user: $user,
    ))->toThrow(ValidationException::class);

    expect($item->refresh()->quantity)->toBe(5)
        ->and($item->stockMovements()->count())->toBe(1);
});

test('an adjustment must explain why stock changed', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 1,
    ], 5);

    expect(fn () => app(RecordStockMovement::class)->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $warehouse->defaultLocation(),
        type: StockMovementType::Adjustment,
        quantity: -1,
        user: $user,
    ))->toThrow(ValidationException::class);

    expect($item->refresh()->quantity)->toBe(5)
        ->and($item->stockMovements()->count())->toBe(1);
});

test('the movement history endpoint is scoped to the current team', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 1,
    ], 4, $warehouse, null, $user);

    app(RecordStockMovement::class)->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $warehouse->defaultLocation(),
        type: StockMovementType::Receipt,
        quantity: 6,
        unitCost: 1,
        reference: 'PO-1002',
        user: $user,
    );

    $url = route('inventories.movements', [
        'current_team' => $team->slug,
        'inventoryItem' => $item->id,
    ]);

    $this->actingAs($user)
        ->getJson($url)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'receipt')
        ->assertJsonPath('data.0.quantity', 6)
        ->assertJsonPath('data.0.reference', 'PO-1002')
        ->assertJsonPath('data.0.warehouseName', $warehouse->name)
        ->assertJsonPath('data.1.type', 'opening');

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->getJson($url)
        ->assertForbidden();
});

test('movement snapshots survive item deletion', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 1,
    ], 2);

    app(RecordStockMovement::class)->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $warehouse->defaultLocation(),
        type: StockMovementType::Adjustment,
        quantity: -2,
        reason: 'Removed before deletion',
        user: $user,
    );

    $item->delete();

    $movement = StockMovement::query()
        ->where('sku', 'BX-2048')
        ->latest('id')
        ->firstOrFail();

    expect($movement->inventory_item_id)->toBeNull()
        ->and($movement->item_name)->toBe('Shipping carton')
        ->and($movement->sku)->toBe('BX-2048')
        ->and(StockMovement::query()->where('sku', 'BX-2048')->count())->toBe(2);
});
