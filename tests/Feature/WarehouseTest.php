<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a team is provisioned with a default warehouse and bin', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();

    $warehouse = $team->defaultWarehouse();

    expect($warehouse)->not->toBeNull()
        ->and($warehouse->code)->toBe('MAIN')
        ->and($warehouse->is_default)->toBeTrue()
        ->and($warehouse->locations()->count())->toBe(1)
        ->and($warehouse->defaultLocation()->code)->toBe('DEFAULT');
});

test('team admins can create warehouses with bins', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $indexUrl = route('warehouses.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('warehouses.store', ['current_team' => $team->slug]), [
            'name' => 'North warehouse',
            'code' => 'north',
            'address' => '12 Dock Road',
            'is_default' => false,
        ])
        ->assertRedirect($indexUrl);

    $warehouse = $team->warehouses()->where('code', 'NORTH')->firstOrFail();

    expect($warehouse->name)->toBe('North warehouse')
        ->and($warehouse->address)->toBe('12 Dock Road')
        ->and($warehouse->locations()->where('is_default', true)->exists())->toBeTrue();

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('warehouses.locations.store', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]), [
            'name' => 'Aisle A',
            'code' => 'a-01',
        ])
        ->assertRedirect($indexUrl);

    $this->assertDatabaseHas('warehouse_locations', [
        'warehouse_id' => $warehouse->id,
        'code' => 'A-01',
        'name' => 'Aisle A',
    ]);

    $this->actingAs($user)
        ->get($indexUrl)
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventories/Warehouses')
            ->has('warehouses', 2)
            ->where('canManage', true)
            ->where('warehouses.0.name', 'Main warehouse')
            ->where('warehouses.0.isDefault', true)
            ->has('warehouses.0.locations', 1)
            ->has('warehouses.1.locations', 2));
});

test('warehouse codes are unique within a team but reusable across teams', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $indexUrl = route('warehouses.index', ['current_team' => $team->slug]);

    $payload = [
        'name' => 'Overflow warehouse',
        'code' => 'OVERFLOW',
        'is_default' => false,
    ];

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('warehouses.store', ['current_team' => $team->slug]), $payload)
        ->assertRedirect($indexUrl);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('warehouses.store', ['current_team' => $team->slug]), $payload)
        ->assertSessionHasErrors('code');

    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->personalTeam();

    $this->actingAs($otherUser)
        ->from(route('warehouses.index', ['current_team' => $otherTeam->slug]))
        ->post(route('warehouses.store', ['current_team' => $otherTeam->slug]), $payload)
        ->assertSessionHasNoErrors();

    expect($otherTeam->warehouses()->where('code', 'OVERFLOW')->exists())->toBeTrue();
});

test('changing the default warehouse moves the default flag', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $original = $team->defaultWarehouse();
    $other = $team->warehouses()->create([
        'name' => 'Store front',
        'code' => 'STORE',
        'is_default' => false,
    ]);

    $this->actingAs($user)
        ->from(route('warehouses.index', ['current_team' => $team->slug]))
        ->patch(route('warehouses.update', [
            'current_team' => $team->slug,
            'warehouse' => $other->id,
        ]), [
            'name' => 'Store front',
            'code' => 'STORE',
            'is_default' => true,
        ])
        ->assertRedirect();

    expect($original->refresh()->is_default)->toBeFalse()
        ->and($other->refresh()->is_default)->toBeTrue()
        ->and($team->defaultWarehouse()->id)->toBe($other->id);
});

test('team members cannot manage warehouses or bins', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $warehouse = $team->defaultWarehouse();
    $indexUrl = route('warehouses.index', ['current_team' => $team->slug]);

    $this->actingAs($member)
        ->from($indexUrl)
        ->post(route('warehouses.store', ['current_team' => $team->slug]), [
            'name' => 'Forbidden warehouse',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->from($indexUrl)
        ->patch(route('warehouses.update', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]), [
            'name' => 'Renamed warehouse',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->from($indexUrl)
        ->post(route('warehouses.locations.store', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]), [
            'name' => 'Forbidden bin',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->from($indexUrl)
        ->delete(route('warehouses.destroy', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get($indexUrl)
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

    $this->actingAs($member)
        ->get(route('warehouses.show', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventories/WarehouseProfile')
            ->where('canManage', false));
});

test('warehouses and bins from another team return not found', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->personalTeam();
    $warehouse = $otherTeam->defaultWarehouse();

    $this->actingAs($user)
        ->from(route('warehouses.index', ['current_team' => $user->currentTeam->slug]))
        ->patch(route('warehouses.update', [
            'current_team' => $user->currentTeam->slug,
            'warehouse' => $warehouse->id,
        ]), [
            'name' => 'Hijacked warehouse',
        ])
        ->assertNotFound();

    expect($warehouse->refresh()->name)->toBe('Main warehouse');
});

test('warehouses with stock and the last warehouse cannot be archived', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $indexUrl = route('warehouses.index', ['current_team' => $team->slug]);
    $warehouse = $team->warehouses()->create([
        'name' => 'Stocked warehouse',
        'code' => 'STOCKED',
        'is_default' => false,
    ]);
    createInventoryItem($team, ['sku' => 'BX-1', 'name' => 'Carton'], 3, $warehouse);

    $this->actingAs($user)
        ->from($indexUrl)
        ->delete(route('warehouses.destroy', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]))
        ->assertRedirect($indexUrl)
        ->assertSessionHasErrors('warehouse');

    expect($warehouse->fresh())->not->toBeNull();

    $empty = $team->warehouses()->create([
        'name' => 'Empty warehouse',
        'code' => 'EMPTY',
        'is_default' => false,
    ]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->delete(route('warehouses.destroy', [
            'current_team' => $team->slug,
            'warehouse' => $empty->id,
        ]))
        ->assertRedirect($indexUrl);

    $this->assertSoftDeleted('warehouses', ['id' => $empty->id]);

    $team->warehouses()->whereKeyNot($warehouse->id)->get()->each->delete();

    $this->actingAs($user)
        ->from($indexUrl)
        ->delete(route('warehouses.destroy', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]))
        ->assertRedirect($indexUrl)
        ->assertSessionHasErrors('warehouse');

    expect($warehouse->fresh())->not->toBeNull();
});

test('team members can view a warehouse profile with its stock and movements', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    createInventoryItem($team, [
        'sku' => 'BX-1',
        'name' => 'Carton',
        'unit_cost' => 2.5,
        'reorder_point' => 10,
    ], 3, $warehouse);

    $this->actingAs($user)
        ->get(route('warehouses.show', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventories/WarehouseProfile')
            ->where('warehouse.id', $warehouse->id)
            ->where('warehouse.isDefault', true)
            ->where('summary.totalUnits', 3)
            ->where('summary.stockedItems', 1)
            ->where('summary.lowStockItems', 1)
            ->where('summary.totalBins', 1)
            ->where('summary.inventoryValue', 7.5)
            ->has('bins', 1)
            ->where('bins.0.totalUnits', 3)
            ->where('bins.0.stockedItems', 1)
            ->has('items', 1)
            ->where('items.0.sku', 'BX-1')
            ->where('items.0.quantity', 3)
            ->where('items.0.status', 'low')
            ->has('movements', 1)
            ->where('movements.0.sku', 'BX-1')
            ->where('movements.0.typeLabel', 'Opening balance')
            ->where('canManage', true));
});

test('warehouse profiles from another team return not found', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWarehouse = $otherUser->personalTeam()->defaultWarehouse();

    $this->actingAs($user)
        ->get(route('warehouses.show', [
            'current_team' => $user->currentTeam->slug,
            'warehouse' => $otherWarehouse->id,
        ]))
        ->assertNotFound();
});

test('default bins and bins holding stock cannot be removed', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $defaultBin = $warehouse->defaultLocation();
    $bin = $warehouse->locations()->create(['code' => 'A-01', 'name' => 'Aisle A']);

    $this->actingAs($user)
        ->from(route('warehouses.index', ['current_team' => $team->slug]))
        ->delete(route('warehouses.locations.destroy', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
            'location' => $defaultBin->id,
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('location');

    expect($defaultBin->fresh())->not->toBeNull();

    createInventoryItem($team, ['sku' => 'BX-1', 'name' => 'Carton'], 2, $warehouse, $bin);

    $this->actingAs($user)
        ->from(route('warehouses.index', ['current_team' => $team->slug]))
        ->delete(route('warehouses.locations.destroy', [
            'current_team' => $team->slug,
            'warehouse' => $warehouse->id,
            'location' => $bin->id,
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('location');

    expect($bin->fresh())->not->toBeNull();
});
