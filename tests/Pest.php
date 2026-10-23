<?php

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create an inventory item and, when a quantity is supplied, record its
 * opening balance through the stock ledger.
 *
 * @param  array<string, mixed>  $attributes
 */
function createInventoryItem(
    Team $team,
    array $attributes = [],
    ?int $quantity = null,
    ?Warehouse $warehouse = null,
    ?WarehouseLocation $location = null,
    ?User $user = null,
): InventoryItem {
    $quantity ??= (int) ($attributes['quantity'] ?? 0);
    unset($attributes['quantity'], $attributes['location']);

    $item = $team->inventoryItems()->create([
        'sku' => fake()->unique()->bothify('SKU-####'),
        'name' => fake()->words(2, true),
        'category' => null,
        'reorder_point' => 0,
        'unit_cost' => 0,
        ...$attributes,
        'quantity' => 0,
    ]);

    if ($quantity <= 0) {
        return $item;
    }

    $warehouse ??= $team->defaultWarehouse();
    $location ??= $warehouse->defaultLocation();

    app(RecordStockMovement::class)->handle(
        team: $team,
        inventoryItem: $item,
        warehouse: $warehouse,
        location: $location,
        type: StockMovementType::Opening,
        quantity: $quantity,
        unitCost: (float) $item->unit_cost,
        reason: 'Opening balance',
        user: $user,
    );

    return $item->refresh();
}
