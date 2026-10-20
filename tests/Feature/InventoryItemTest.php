<?php

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('team members can create inventory items that are saved with their photos', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $imageId = fake()->uuid();

    $response = $this->actingAs($user)->post(
        route('inventories.store', ['current_team' => $team->slug]),
        [
            'name' => 'Shipping carton',
            'sku' => 'bx-2048',
            'category' => 'Packaging',
            'location' => 'North warehouse',
            'quantity' => 25,
            'reorder_point' => 5,
            'unit_cost' => '2.50',
            'image_ids' => [$imageId],
            'main_image_id' => $imageId,
            'images' => [UploadedFile::fake()->image('carton.png')],
        ],
    );

    $response->assertRedirect(route('inventories.index', ['current_team' => $team->slug]));
    $item = InventoryItem::query()->firstOrFail();

    expect($item->team_id)->toBe($team->id)
        ->and($item->sku)->toBe('BX-2048')
        ->and($item->name)->toBe('Shipping carton')
        ->and($item->quantity)->toBe(25)
        ->and($item->reorder_point)->toBe(5)
        ->and($item->images)->toHaveCount(1)
        ->and($item->images[0]['id'])->toBe($imageId)
        ->and(Storage::disk('public')->allFiles('inventory/'.$team->id))->toHaveCount(1);
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

    $response = $this->actingAs($user)->post(
        route('inventories.store', ['current_team' => $team->slug]),
        [
            'name' => 'Duplicate carton',
            'sku' => 'BX-2048',
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
        ->has('inventoryItems', 1)
        ->where('inventoryItems.0.sku', 'BX-2048')
        ->where('inventoryItems.0.name', 'Shipping carton')
        ->where('inventoryItems.0.reorderPoint', 5),
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

    $response = $this->actingAs($user)->post(
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

    $response->assertRedirect(route('inventories.index', ['current_team' => $team->slug]));
    $item->refresh();

    expect($item->images)->toHaveCount(2)
        ->and($item->main_image_id)->toBe($newImageId)
        ->and(Storage::disk('public')->allFiles('inventory/'.$team->id))->toHaveCount(2);
});
