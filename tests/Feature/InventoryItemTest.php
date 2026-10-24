<?php

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Enums\TeamRole;
use App\Models\InventoryItem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('team members can create inventory items that are saved with their photos', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $imageId = fake()->uuid();
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $response = $this->actingAs($user)->from($indexUrl)->post(
        route('inventories.store', ['current_team' => $team->slug]),
        [
            'name' => 'Shipping carton',
            'sku' => 'bx-2048',
            'category' => 'Packaging',
            'warehouse_id' => $warehouse->id,
            'quantity' => 25,
            'reorder_point' => 5,
            'unit_cost' => '2.50',
            'image_ids' => [$imageId],
            'main_image_id' => $imageId,
            'images' => [UploadedFile::fake()->image('carton.png')],
        ],
    );

    $response->assertRedirect($indexUrl);
    $item = InventoryItem::query()->firstOrFail();

    expect($item->team_id)->toBe($team->id)
        ->and($item->sku)->toBe('BX-2048')
        ->and($item->name)->toBe('Shipping carton')
        ->and($item->quantity)->toBe(25)
        ->and($item->reorder_point)->toBe(5)
        ->and($item->images)->toHaveCount(1)
        ->and($item->images[0]['id'])->toBe($imageId)
        ->and(Storage::disk('public')->allFiles('inventory/'.$team->id))->toHaveCount(1);

    $this->assertDatabaseHas('stock_levels', [
        'inventory_item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 25,
    ]);
    $this->assertDatabaseHas('stock_movements', [
        'inventory_item_id' => $item->id,
        'type' => 'opening',
        'quantity' => 25,
        'quantity_before' => 0,
        'quantity_after' => 25,
    ]);
});

test('inventory sku must be unique within a team', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Existing carton',
        'quantity' => 2,
        'reorder_point' => 1,
        'unit_cost' => 1.25,
    ]);

    $response = $this->actingAs($user)
        ->from(route('inventories.index', ['current_team' => $team->slug]))
        ->post(
            route('inventories.store', ['current_team' => $team->slug]),
            [
                'name' => 'Duplicate carton',
                'sku' => 'BX-2048',
                'warehouse_id' => $team->defaultWarehouse()->id,
                'quantity' => 0,
                'reorder_point' => 0,
                'unit_cost' => 0,
            ],
        );

    $response->assertSessionHasErrors('sku');
    $this->assertDatabaseCount('inventory_items', 1);
});

test('inventory page loads saved items for the current team', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'quantity' => 25,
        'reorder_point' => 5,
        'unit_cost' => 2.50,
    ]);

    $response = $this->actingAs($user)
        ->get(route('inventories.index', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('inventories/Index')
        ->has('inventoryItems.data', 1)
        ->where('inventoryItems.data.0.sku', 'BX-2048')
        ->where('inventoryItems.data.0.name', 'Shipping carton')
        ->where('inventoryItems.data.0.reorderPoint', 5),
    );
});

test('team members can save product image changes for an inventory item', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $existingImageId = fake()->uuid();
    $newImageId = fake()->uuid();
    $existingPath = 'inventory/'.$team->id.'/existing.png';
    Storage::disk('public')->put($existingPath, 'existing image');
    $item = $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'quantity' => 25,
        'reorder_point' => 5,
        'unit_cost' => 2.50,
        'images' => [[
            'id' => $existingImageId,
            'name' => 'existing.png',
            'src' => Storage::disk('public')->url($existingPath),
            'path' => $existingPath,
        ]],
        'main_image_id' => $existingImageId,
    ]);
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $response = $this->actingAs($user)->from($indexUrl)->post(
        route('inventories.images.update', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]),
        [
            'image_ids' => [$existingImageId, $newImageId],
            'new_image_ids' => [$newImageId],
            'new_images' => [UploadedFile::fake()->image('new-carton.png')],
            'main_image_id' => $newImageId,
        ],
    );

    $response->assertRedirect($indexUrl);
    $item->refresh();

    expect($item->images)->toHaveCount(2)
        ->and($item->main_image_id)->toBe($newImageId)
        ->and(Storage::disk('public')->allFiles('inventory/'.$team->id))->toHaveCount(2);
});

test('inventory items can be updated and the changed fields are logged', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $item = $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'category' => 'Packaging',
        'quantity' => 25,
        'reorder_point' => 5,
        'unit_cost' => 2.50,
    ]);
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $response = $this->actingAs($user)->from($indexUrl)->patch(
        route('inventories.update', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]),
        [
            'name' => '  Insulated carton  ',
            'sku' => ' bx-4096 ',
            'category' => 'Packaging',
            'quantity' => 999,
            'reorder_point' => 8,
            'unit_cost' => '3.25',
        ],
    );

    $response->assertRedirect($indexUrl);
    $item->refresh();

    expect($item->name)->toBe('Insulated carton')
        ->and($item->sku)->toBe('BX-4096')
        ->and($item->reorder_point)->toBe(8)
        ->and($item->quantity)->toBe(25);

    $activity = Activity::query()
        ->where('description', 'Inventory Updated')
        ->firstOrFail();

    expect($activity->getProperty('team_id'))->toBe($team->id)
        ->and($activity->getProperty('item_name'))->toBe('Insulated carton')
        ->and($activity->getProperty('sku'))->toBe('BX-4096')
        ->and($activity->getProperty('changes.name.from'))->toBe('Shipping carton')
        ->and($activity->getProperty('changes.name.to'))->toBe('Insulated carton')
        ->and($activity->getProperty('changes'))->not->toHaveKey('category')
        ->and($activity->causer_id)->toBe($user->id);
});

test('duplicate sku on update is rejected', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $item = $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'quantity' => 1,
        'reorder_point' => 1,
        'unit_cost' => 1,
    ]);
    $team->inventoryItems()->create([
        'sku' => 'BX-4096',
        'name' => 'Other carton',
        'quantity' => 1,
        'reorder_point' => 1,
        'unit_cost' => 1,
    ]);

    $response = $this->actingAs($user)
        ->from(route('inventories.index', ['current_team' => $team->slug]))
        ->patch(
            route('inventories.update', [
                'current_team' => $team->slug,
                'inventoryItem' => $item->id,
            ]),
            [
                'name' => 'Shipping carton',
                'sku' => 'BX-4096',
                'reorder_point' => 1,
                'unit_cost' => 1,
            ],
        );

    $response->assertSessionHasErrors('sku');
});

test('deleting an inventory item removes it and its stored images', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $path = 'inventory/'.$team->id.'/carton.png';
    Storage::disk('public')->put($path, 'image');
    $item = $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'quantity' => 0,
        'reorder_point' => 1,
        'unit_cost' => 1,
        'images' => [[
            'id' => fake()->uuid(),
            'name' => 'carton.png',
            'src' => Storage::disk('public')->url($path),
            'path' => $path,
        ]],
    ]);
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $response = $this->actingAs($user)->from($indexUrl)->delete(
        route('inventories.destroy', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]),
    );

    $response->assertRedirect($indexUrl);
    $this->assertDatabaseMissing('inventory_items', ['id' => $item->id]);
    Storage::disk('public')->assertMissing($path);

    $activity = Activity::query()
        ->where('description', 'Inventory Deleted')
        ->firstOrFail();

    expect($activity->getProperty('item_name'))->toBe('Shipping carton')
        ->and($activity->getProperty('sku'))->toBe('BX-2048')
        ->and($activity->getProperty('image_names'))->toBe(['carton.png'])
        ->and($activity->getProperty('team_id'))->toBe($team->id);
});

test('receiving stock persists the quantity and logs before and after', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'reorder_point' => 2,
        'unit_cost' => 1,
    ], 5);
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $response = $this->actingAs($user)->from($indexUrl)->post(
        route('inventories.stock', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]),
        [
            'direction' => 'receive',
            'warehouse_id' => $warehouse->id,
            'amount' => 7,
            'reference' => 'PO-1001',
            'reason' => 'Delivery',
        ],
    );

    $response->assertRedirect($indexUrl);
    expect($item->refresh()->quantity)->toBe(12);

    $this->assertDatabaseHas('stock_movements', [
        'inventory_item_id' => $item->id,
        'type' => 'receipt',
        'quantity' => 7,
        'quantity_before' => 5,
        'quantity_after' => 12,
        'reference' => 'PO-1001',
    ]);

    $activity = Activity::query()
        ->where('description', 'Inventory Stock Received')
        ->firstOrFail();

    expect($activity->getProperty('team_id'))->toBe($team->id)
        ->and($activity->getProperty('direction'))->toBe('in')
        ->and($activity->getProperty('amount'))->toBe(7)
        ->and($activity->getProperty('quantity_before'))->toBe(5)
        ->and($activity->getProperty('quantity_after'))->toBe(12)
        ->and($activity->getProperty('reason'))->toBe('Delivery');
});

test('removing stock never drops the quantity below zero', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'reorder_point' => 2,
        'unit_cost' => 1,
    ], 3);
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(
            route('inventories.stock', [
                'current_team' => $team->slug,
                'inventoryItem' => $item->id,
            ]),
            [
                'direction' => 'remove',
                'warehouse_id' => $warehouse->id,
                'amount' => 10,
                'reason' => 'Damaged units',
            ],
        )
        ->assertSessionHasErrors('amount');

    expect($item->refresh()->quantity)->toBe(3);
    $this->assertDatabaseMissing('stock_movements', [
        'inventory_item_id' => $item->id,
        'type' => 'adjustment',
    ]);
});

test('removing stock requires a reason', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'reorder_point' => 2,
        'unit_cost' => 1,
    ], 3);

    $this->actingAs($user)
        ->from(route('inventories.index', ['current_team' => $team->slug]))
        ->post(
            route('inventories.stock', [
                'current_team' => $team->slug,
                'inventoryItem' => $item->id,
            ]),
            [
                'direction' => 'remove',
                'warehouse_id' => $warehouse->id,
                'amount' => 1,
            ],
        )
        ->assertSessionHasErrors('reason');

    expect($item->refresh()->quantity)->toBe(3);
});

test('members cannot perform inventory mutations', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $item = $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'quantity' => 5,
        'reorder_point' => 2,
        'unit_cost' => 1,
    ]);
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $this->actingAs($member)->from($indexUrl)->post(
        route('inventories.store', ['current_team' => $team->slug]),
        [
            'name' => 'Forbidden carton',
            'sku' => 'BX-0000',
            'quantity' => 1,
            'reorder_point' => 1,
            'unit_cost' => 1,
        ],
    )->assertForbidden();

    $this->actingAs($member)->from($indexUrl)->patch(
        route('inventories.update', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]),
        [
            'name' => 'Renamed carton',
            'sku' => 'BX-2048',
            'reorder_point' => 2,
            'unit_cost' => 1,
        ],
    )->assertForbidden();

    $this->actingAs($member)->from($indexUrl)->post(
        route('inventories.stock', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]),
        [
            'direction' => 'receive',
            'amount' => 1,
        ],
    )->assertForbidden();

    $this->actingAs($member)->from($indexUrl)->delete(
        route('inventories.destroy', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]),
    )->assertForbidden();

    $this->assertDatabaseHas('inventory_items', [
        'id' => $item->id,
        'name' => 'Shipping carton',
        'quantity' => 5,
    ]);

    $this->actingAs($member)
        ->get($indexUrl)
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
});

test('inventory items from another team return not found', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->personalTeam();
    $item = $otherTeam->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Other carton',
        'quantity' => 5,
        'reorder_point' => 2,
        'unit_cost' => 1,
    ]);
    $indexUrl = route('inventories.index', ['current_team' => $user->currentTeam->slug]);

    $this->actingAs($user)->from($indexUrl)->patch(
        route('inventories.update', [
            'current_team' => $user->currentTeam->slug,
            'inventoryItem' => $item->id,
        ]),
        [
            'name' => 'Hijacked carton',
            'sku' => 'BX-2048',
            'reorder_point' => 2,
            'unit_cost' => 1,
        ],
    )->assertNotFound();

    expect($item->refresh()->name)->toBe('Other carton');
});

test('inventory index paginates items and reports team summary values', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();

    foreach (range(1, 30) as $number) {
        $team->inventoryItems()->create([
            'sku' => 'SKU-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'name' => 'Item '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'quantity' => 1,
            'reorder_point' => 5,
            'unit_cost' => 1.50,
        ]);
    }

    $response = $this->actingAs($user)
        ->get(route('inventories.index', ['current_team' => $team->slug]));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('inventoryItems.data', 25)
        ->where('inventoryItems.total', 30)
        ->where('inventoryItems.per_page', 25)
        ->where('summary.totalItems', 30)
        ->where('summary.totalUnits', 30)
        ->where('summary.lowStockCount', 30)
        ->where('summary.outOfStockCount', 0)
        ->where('summary.inventoryValue', 45),
    );

    $this->actingAs($user)
        ->get(route('inventories.index', [
            'current_team' => $team->slug,
            'page' => 2,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('inventoryItems.data', 5)
            ->where('inventoryItems.current_page', 2),
        );
});

test('inventory index supports server side search and status filters', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $shelfA = $warehouse->locations()->create(['code' => 'SHELF-A', 'name' => 'Shelf A']);
    $shelfB = $warehouse->locations()->create(['code' => 'SHELF-B', 'name' => 'Shelf B']);

    createInventoryItem($team, [
        'sku' => 'AA-1',
        'name' => 'Widget Alpha',
        'category' => 'Tools',
        'reorder_point' => 2,
        'unit_cost' => 1,
    ], 10, $warehouse, $shelfA);
    createInventoryItem($team, [
        'sku' => 'BB-2',
        'name' => 'Gadget Beta',
        'category' => 'Packaging',
        'reorder_point' => 5,
        'unit_cost' => 1,
    ], 1, $warehouse, $shelfB);
    createInventoryItem($team, [
        'sku' => 'CC-3',
        'name' => 'Doohickey',
        'category' => 'Tools',
        'reorder_point' => 3,
        'unit_cost' => 1,
    ], 0, $warehouse);

    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->get($indexUrl.'?search=gadget')
        ->assertInertia(fn (Assert $page) => $page
            ->has('inventoryItems.data', 1)
            ->where('inventoryItems.data.0.sku', 'BB-2')
            ->where('filters.search', 'gadget'),
        );

    $this->actingAs($user)
        ->get($indexUrl.'?search=Shelf+A')
        ->assertInertia(fn (Assert $page) => $page
            ->has('inventoryItems.data', 1)
            ->where('inventoryItems.data.0.sku', 'AA-1'),
        );

    $this->actingAs($user)
        ->get($indexUrl.'?status=low')
        ->assertInertia(fn (Assert $page) => $page
            ->has('inventoryItems.data', 1)
            ->where('inventoryItems.data.0.sku', 'BB-2')
            ->where('filters.status', 'low'),
        );

    $this->actingAs($user)
        ->get($indexUrl.'?status=out')
        ->assertInertia(fn (Assert $page) => $page
            ->has('inventoryItems.data', 1)
            ->where('inventoryItems.data.0.sku', 'CC-3')
            ->where('filters.status', 'out'),
        );

    $this->actingAs($user)
        ->get($indexUrl.'?status=all')
        ->assertInertia(fn (Assert $page) => $page
            ->has('inventoryItems.data', 3)
            ->where('filters.status', 'all'),
        );
});

test('inventory index exposes team activities and management permissions', function () {
    $user = User::factory()->create(['name' => 'Taylor Otwell']);
    $team = $user->personalTeam();
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $this->actingAs($user)->from($indexUrl)->post(
        route('inventories.store', ['current_team' => $team->slug]),
        [
            'name' => 'Shipping carton',
            'sku' => 'BX-2048',
            'warehouse_id' => $team->defaultWarehouse()->id,
            'quantity' => 2,
            'reorder_point' => 1,
            'unit_cost' => 1,
        ],
    )->assertRedirect($indexUrl);

    $activity = Activity::query()
        ->where('description', 'Inventory Created')
        ->firstOrFail();

    $this->actingAs($user)
        ->get($indexUrl)
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities', 1)
            ->where('activities.0.description', 'Inventory Created')
            ->where('activities.0.itemName', 'Shipping carton')
            ->where('activities.0.sku', 'BX-2048')
            ->where('activities.0.userName', 'Taylor Otwell')
            ->where('activities.0.createdAt', $activity->created_at->toIso8601String())
            ->where('canManage', true)
            ->where('filters.search', '')
            ->where('filters.status', 'all'),
        );
});

test('inventory item profile page shows stock, movement history, and management permissions', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $warehouse = $team->defaultWarehouse();
    $bin = $warehouse->defaultLocation();
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'category' => 'Packaging',
        'reorder_point' => 5,
        'unit_cost' => 2.50,
    ], 10, $warehouse, $bin);

    app(RecordStockMovement::class)->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $bin,
        type: StockMovementType::Receipt,
        quantity: 5,
        unitCost: 2.50,
        reference: 'PO-1001',
        reason: 'Delivery',
        user: $user,
    );

    $response = $this->actingAs($user)->get(route('inventories.show', [
        'current_team' => $team->slug,
        'inventoryItem' => $item->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('inventories/Show')
        ->where('item.sku', 'BX-2048')
        ->where('item.name', 'Shipping carton')
        ->where('item.category', 'Packaging')
        ->where('item.quantity', 15)
        ->where('item.reorderPoint', 5)
        ->where('item.unitCost', 2.5)
        ->has('item.stock', 1)
        ->where('item.stock.0.warehouseId', $warehouse->id)
        ->where('item.stock.0.locationId', $bin->id)
        ->where('item.stock.0.quantity', 15)
        ->has('movements', 2)
        ->where('movements.0.type', 'receipt')
        ->where('movements.0.quantity', 5)
        ->where('movements.0.reason', 'Delivery')
        ->where('movementCount', 2)
        ->where('canManage', true)
        ->has('warehouses', 1),
    );
});

test('team members without management rights can view the inventory profile in read only mode', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'reorder_point' => 5,
        'unit_cost' => 1.50,
    ], 4);

    $this->actingAs($member)
        ->get(route('inventories.show', [
            'current_team' => $team->slug,
            'inventoryItem' => $item->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventories/Show')
            ->where('item.sku', 'BX-2048')
            ->where('canManage', false),
        );
});

test('inventory profiles from another team return not found', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->personalTeam();
    $item = createInventoryItem($otherTeam, [
        'sku' => 'BX-2048',
        'name' => 'Other carton',
        'reorder_point' => 2,
        'unit_cost' => 1,
    ], 5);

    $this->actingAs($user)
        ->get(route('inventories.show', [
            'current_team' => $user->currentTeam->slug,
            'inventoryItem' => $item->id,
        ]))
        ->assertNotFound();
});
