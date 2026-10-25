<?php

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Enums\TeamRole;
use App\Models\InventoryItem;
use App\Models\SalesFulfillment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesRefund;
use App\Models\SalesReturn;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->personalTeam();
    $this->warehouse = $this->team->defaultWarehouse();
    $this->location = $this->warehouse->defaultLocation();
    $this->item = createInventoryItem($this->team, [
        'name' => 'Returnable carton', 'sku' => 'SALE-1', 'unit_cost' => '2.15',
    ], 10, $this->warehouse, $this->location, $this->owner);
    $this->actingAs($this->owner);
    $this->salesUrl = fn (string $action): string => route('sales-orders.'.$action, [
        'current_team' => $this->team->slug,
        'salesOrder' => $this->order->id,
    ]);
    $response = $this->post(route('sales-orders.store', ['current_team' => $this->team->slug]), [
        'customer_name' => 'Ada Buyer',
        'customer_email' => 'ada@example.test',
        'customer_phone' => '555-0100',
        'customer_address' => '123 Market Street',
        'currency' => 'PHP',
        'items' => [['inventory_item_id' => $this->item->id, 'quantity' => 5, 'unit_price' => '10.01']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->order = SalesOrder::query()->sole();
    $response->assertRedirect(($this->salesUrl)('show'));
    $this->line = $this->order->items()->sole();
    $this->reserveSales = function (int $quantity = 5): StockReservation {
        $this->post(($this->salesUrl)('reserve'), [
            'sales_order_item_id' => $this->line->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_location_id' => $this->location->id,
            'quantity' => $quantity,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return StockReservation::query()->latest('id')->firstOrFail();
    };
    $this->fulfillSales = function (int $quantity = 5): SalesFulfillment {
        $reservation = ($this->reserveSales)($quantity);
        $this->post(($this->salesUrl)('fulfill'), [
            'idempotency_key' => fake()->uuid(), 'reservation_id' => $reservation->id, 'quantity' => $quantity,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SalesFulfillment::query()->latest('id')->firstOrFail();
    };
    $this->returnSales = function (SalesFulfillment $fulfillment, array $overrides = []): SalesReturn {
        $this->post(($this->salesUrl)('returns.store'), [
            'idempotency_key' => fake()->uuid(), 'fulfillment_id' => $fulfillment->id,
            'quantity' => 1, 'condition' => 'good', 'accepted' => true, 'reason' => 'Customer return',
            'warehouse_id' => $this->warehouse->id, 'warehouse_location_id' => $this->location->id,
            ...$overrides,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SalesReturn::query()->latest('id')->firstOrFail();
    };
});

test('drafts snapshot customer and selling prices without changing stock', function () {
    $this->assertDatabaseHas('sales_orders', [
        'id' => $this->order->id, 'status' => 'draft', 'customer_name' => 'Ada Buyer',
        'customer_email' => 'ada@example.test', 'currency' => 'PHP', 'total_amount' => '50.05',
        'customer_phone' => '555-0100', 'customer_address' => '123 Market Street',
    ]);
    $this->item->update(['name' => 'Renamed carton', 'unit_cost' => '9.99']);

    expect($this->line->fresh()->item_name)->toBe('Returnable carton')
        ->and($this->line->fresh()->unit_price)->toBe('10.01')
        ->and($this->line->fresh()->line_total)->toBe('50.05')
        ->and($this->item->fresh()->quantity)->toBe(10)
        ->and($this->item->stockMovements()->count())->toBe(1);
    $this->get(($this->salesUrl)('index'))->assertOk();
    $this->get(($this->salesUrl)('show'))->assertOk();
});

test('invalid multi-line creation rolls back the entire draft', function () {
    $foreignItem = createInventoryItem(User::factory()->create()->personalTeam());
    $this->postJson(route('sales-orders.store', ['current_team' => $this->team->slug]), [
        'customer_name' => 'Invalid buyer', 'currency' => 'PHP',
        'items' => [
            ['inventory_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => '0.10'],
            ['inventory_item_id' => $foreignItem->id, 'quantity' => 1, 'unit_price' => '1.00'],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.1.inventory_item_id');

    $this->assertDatabaseCount('sales_orders', 1);
    $this->assertDatabaseCount('sales_order_items', 1);
    expect($this->item->fresh()->quantity)->toBe(10);
});

test('reservation leaves on-hand unchanged and partial fulfillment consumes only its own reserved quantity', function () {
    $first = ($this->reserveSales)(3);
    $second = ($this->reserveSales)(2);
    expect($this->item->fresh()->quantity)->toBe(10)
        ->and($this->item->stockMovements()->count())->toBe(1);

    $this->post(($this->salesUrl)('fulfill'), [
        'idempotency_key' => fake()->uuid(), 'reservation_id' => $first->id, 'quantity' => 2,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->item->fresh()->quantity)->toBe(8)
        ->and($this->item->stockLevels()->sum('quantity'))->toBe(8)
        ->and(SalesFulfillment::query()->sole()->stock_reservation_id)->toBe($first->id)
        ->and($first->fresh()->remaining_quantity)->toBe(1)
        ->and($second->fresh()->remaining_quantity)->toBe(2);

    $this->postJson(($this->salesUrl)('fulfill'), [
        'idempotency_key' => fake()->uuid(), 'reservation_id' => $first->id, 'quantity' => 2,
    ])->assertUnprocessable();
    expect($this->item->fresh()->quantity)->toBe(8);

    $this->post(($this->salesUrl)('fulfill'), [
        'idempotency_key' => fake()->uuid(), 'reservation_id' => $second->id, 'quantity' => 2,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->item->fresh()->quantity)->toBe(6)
        ->and(SalesFulfillment::query()->sum('quantity'))->toBe(4);
});

test('sales drafts reject currencies other than Philippine pesos', function () {
    $this->postJson(route('sales-orders.store', ['current_team' => $this->team->slug]), [
        'customer_name' => 'Unsupported currency buyer', 'currency' => 'USD',
        'items' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => '10.01']],
    ])->assertUnprocessable()->assertJsonValidationErrors('currency');

    $this->assertDatabaseCount('sales_orders', 1);
    $this->assertDatabaseCount('sales_order_items', 1);
});

test('manual stock removal and scanner picking cannot consume sales reservations', function () {
    ($this->reserveSales)(5);
    $this->postJson(route('inventories.stock', [
        'current_team' => $this->team->slug, 'inventoryItem' => $this->item->id,
    ]), [
        'direction' => 'remove', 'amount' => 6, 'warehouse_id' => $this->warehouse->id,
        'warehouse_location_id' => $this->location->id, 'reason' => 'Manual picking',
    ])->assertUnprocessable();
    $this->postJson(route('inventory-scans.store', ['current_team' => $this->team->slug]), [
        'workflow' => 'picking', 'transaction_id' => fake()->uuid(), 'scan_id' => fake()->uuid(),
        'item_code' => $this->item->sku, 'bin_code' => $this->location->code,
        'warehouse_id' => $this->warehouse->id, 'quantity' => 6,
    ])->assertUnprocessable();

    expect($this->item->fresh()->quantity)->toBe(10)
        ->and($this->item->stockMovements()->count())->toBe(1);
    $this->assertDatabaseCount('inventory_scan_events', 0);

    $this->post(route('inventories.stock', [
        'current_team' => $this->team->slug, 'inventoryItem' => $this->item->id,
    ]), [
        'direction' => 'remove', 'amount' => 5, 'warehouse_id' => $this->warehouse->id,
        'warehouse_location_id' => $this->location->id, 'reason' => 'Unreserved picking',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->item->fresh()->quantity)->toBe(5);
});

test('cancelling a partially fulfilled order releases the remainder once and preserves return eligibility', function () {
    $reservation = ($this->reserveSales)(5);
    $this->post(($this->salesUrl)('fulfill'), [
        'idempotency_key' => fake()->uuid(), 'reservation_id' => $reservation->id, 'quantity' => 2,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $fulfillment = SalesFulfillment::query()->sole();
    $payload = ['reason' => 'Customer cancelled remainder'];
    $this->post(($this->salesUrl)('cancel'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $eventCount = $this->order->events()->count();
    $this->post(($this->salesUrl)('cancel'), $payload)->assertRedirect()->assertSessionHasNoErrors();

    expect($this->item->fresh()->quantity)->toBe(8)
        ->and($this->order->events()->count())->toBe($eventCount)
        ->and(SalesFulfillment::query()->sole()->quantity)->toBe(2)
        ->and($this->line->fresh()->fulfilled_quantity)->toBe(2)
        ->and($this->line->fresh()->cancelled_quantity)->toBe(3)
        ->and($reservation->fresh()->remaining_quantity)->toBe(0);
    $this->post(route('inventories.stock', [
        'current_team' => $this->team->slug, 'inventoryItem' => $this->item->id,
    ]), [
        'direction' => 'remove', 'amount' => 8, 'warehouse_id' => $this->warehouse->id,
        'warehouse_location_id' => $this->location->id, 'reason' => 'Released stock',
    ])->assertRedirect()->assertSessionHasNoErrors();
    ($this->returnSales)($fulfillment);
    expect($this->item->fresh()->quantity)->toBe(1);
});

test('fulfillment replay is durable after completion and changed payloads cannot reuse the key', function () {
    $reservation = ($this->reserveSales)(5);
    $payload = ['idempotency_key' => fake()->uuid(), 'reservation_id' => $reservation->id, 'quantity' => 5];
    $this->post(($this->salesUrl)('fulfill'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $eventCount = $this->order->events()->count();
    $this->post(($this->salesUrl)('fulfill'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson(($this->salesUrl)('fulfill'), [...$payload, 'quantity' => 4])->assertUnprocessable();

    expect($this->item->fresh()->quantity)->toBe(5)
        ->and($this->item->stockMovements()->count())->toBe(2)
        ->and($this->order->events()->count())->toBe($eventCount);
    $this->assertDatabaseCount('sales_fulfillments', 1);
});

test('accepted good returns restock once at original inventory cost and reject changed replay payloads', function () {
    $fulfillment = ($this->fulfillSales)();
    $this->item->update(['unit_cost' => '8.99']);
    $payload = [
        'idempotency_key' => fake()->uuid(), 'fulfillment_id' => $fulfillment->id, 'quantity' => 5,
        'condition' => 'good', 'accepted' => true, 'reason' => 'Unopened goods',
        'warehouse_id' => $this->warehouse->id, 'warehouse_location_id' => $this->location->id,
    ];
    $this->post(($this->salesUrl)('returns.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $eventCount = $this->order->events()->count();
    $this->post(($this->salesUrl)('returns.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson(($this->salesUrl)('returns.store'), [...$payload, 'quantity' => 4])->assertUnprocessable();

    expect($this->item->fresh()->quantity)->toBe(10)
        ->and($this->item->stockMovements()->count())->toBe(3)
        ->and($this->item->stockMovements()->latest('id')->first()->unit_cost)->toBe('2.15')
        ->and($this->order->events()->count())->toBe($eventCount);
    $this->assertDatabaseCount('sales_returns', 1);
});

test('good return destination validation leaves stock returns and history unchanged', function () {
    $fulfillment = ($this->fulfillSales)();
    $eventCount = $this->order->events()->count();
    $payload = [
        'idempotency_key' => fake()->uuid(), 'fulfillment_id' => $fulfillment->id,
        'quantity' => 1, 'condition' => 'good', 'accepted' => true, 'reason' => 'Missing destination',
    ];
    $this->postJson(($this->salesUrl)('returns.store'), $payload)->assertUnprocessable();
    $foreignWarehouse = User::factory()->create()->personalTeam()->defaultWarehouse();
    $this->postJson(($this->salesUrl)('returns.store'), [
        ...$payload, 'warehouse_id' => $this->warehouse->id,
        'warehouse_location_id' => $foreignWarehouse->defaultLocation()->id,
    ])->assertNotFound();

    $this->assertDatabaseCount('sales_returns', 0);
    expect($this->item->fresh()->quantity)->toBe(5)
        ->and($this->item->stockMovements()->count())->toBe(2)
        ->and($this->order->events()->count())->toBe($eventCount);
});

test('damaged accepted returns may be refunded without restocking while rejected returns consume the return cap', function () {
    $fulfillment = ($this->fulfillSales)();
    $damaged = ($this->returnSales)($fulfillment, [
        'quantity' => 2, 'condition' => 'damaged', 'warehouse_id' => null, 'warehouse_location_id' => null,
    ]);
    $rejected = ($this->returnSales)($fulfillment, [
        'quantity' => 3, 'accepted' => false, 'warehouse_id' => null, 'warehouse_location_id' => null,
    ]);
    $this->post(($this->salesUrl)('refunds.store'), [
        'idempotency_key' => fake()->uuid(), 'sales_return_id' => $damaged->id,
        'amount' => '20.02', 'status' => 'refunded', 'reference' => 'Manual reimbursement',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson(($this->salesUrl)('refunds.store'), [
        'idempotency_key' => fake()->uuid(), 'sales_return_id' => $rejected->id,
        'amount' => '0.01', 'status' => 'pending',
    ])->assertUnprocessable();
    $this->postJson(($this->salesUrl)('returns.store'), [
        'idempotency_key' => fake()->uuid(), 'fulfillment_id' => $fulfillment->id,
        'quantity' => 1, 'accepted' => false, 'condition' => 'unsellable', 'reason' => 'Excess return',
    ])->assertUnprocessable();

    expect($this->item->fresh()->quantity)->toBe(5)
        ->and($this->item->stockMovements()->count())->toBe(2);
    $this->assertDatabaseCount('sales_returns', 2);
    $this->assertDatabaseCount('sales_refunds', 1);
});

test('refund caps are exact per return and cancelled pending refunds release their allowance', function () {
    $fulfillment = ($this->fulfillSales)();
    $return = ($this->returnSales)($fulfillment);
    ($this->returnSales)($fulfillment, ['quantity' => 4]);
    $payload = [
        'idempotency_key' => fake()->uuid(), 'sales_return_id' => $return->id,
        'amount' => '6.00', 'status' => 'pending',
    ];
    $this->post(($this->salesUrl)('refunds.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $refund = SalesRefund::query()->sole();
    $this->postJson(($this->salesUrl)('refunds.store'), [
        ...$payload, 'idempotency_key' => fake()->uuid(), 'amount' => '4.02',
    ])->assertUnprocessable();
    $this->post(($this->salesUrl)('refunds.store'), [
        ...$payload, 'idempotency_key' => fake()->uuid(), 'amount' => '4.01', 'status' => 'refunded',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $updateUrl = route('sales-orders.refunds.update', [
        'current_team' => $this->team->slug, 'salesOrder' => $this->order->id, 'salesRefund' => $refund->id,
    ]);
    $this->patch($updateUrl, ['status' => 'cancelled'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(($this->salesUrl)('refunds.store'), [
        ...$payload, 'idempotency_key' => fake()->uuid(), 'status' => 'refunded',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $terminal = SalesRefund::query()->where('status', 'refunded')->firstOrFail();
    $this->patchJson(route('sales-orders.refunds.update', [
        'current_team' => $this->team->slug, 'salesOrder' => $this->order->id, 'salesRefund' => $terminal->id,
    ]), ['status' => 'cancelled'])->assertUnprocessable();
    expect(SalesRefund::query()->where('status', 'refunded')->sum('amount'))->toEqual(10.01);
});

test('refund replay remains unchanged after a pending refund becomes terminal', function () {
    $return = ($this->returnSales)(($this->fulfillSales)());
    $payload = [
        'idempotency_key' => fake()->uuid(), 'sales_return_id' => $return->id,
        'amount' => '10.01', 'status' => 'pending', 'reference' => 'REF-1',
    ];
    $this->post(($this->salesUrl)('refunds.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $refund = SalesRefund::query()->sole();
    $this->patch(route('sales-orders.refunds.update', [
        'current_team' => $this->team->slug, 'salesOrder' => $this->order->id, 'salesRefund' => $refund->id,
    ]), ['status' => 'refunded', 'reference' => 'Settled manually'])->assertRedirect()->assertSessionHasNoErrors();
    $eventCount = $this->order->events()->count();
    $this->post(($this->salesUrl)('refunds.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson(($this->salesUrl)('refunds.store'), [...$payload, 'amount' => '9.00'])->assertUnprocessable();

    $this->assertDatabaseCount('sales_refunds', 1);
    $this->assertDatabaseHas('sales_refunds', ['id' => $refund->id, 'status' => 'refunded', 'reference' => 'Settled manually']);
    expect($this->order->events()->count())->toBe($eventCount);
});

test('fulfillment return and refund idempotency keys have durable unique constraints', function () {
    $fulfillment = ($this->fulfillSales)();
    $return = ($this->returnSales)($fulfillment);
    $this->post(($this->salesUrl)('refunds.store'), [
        'idempotency_key' => fake()->uuid(), 'sales_return_id' => $return->id,
        'amount' => '10.01', 'status' => 'refunded',
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach ([$fulfillment, $return, SalesRefund::query()->sole()] as $record) {
        expect(fn () => $record->replicate()->save())->toThrow(QueryException::class);
    }
    $this->assertDatabaseCount('sales_fulfillments', 1);
    $this->assertDatabaseCount('sales_returns', 1);
    $this->assertDatabaseCount('sales_refunds', 1);
});

test('reservation validation rejects unrelated lines locations and excess quantities without partial writes', function () {
    $otherOrder = SalesOrder::factory()->for($this->team)->create();
    $otherLine = SalesOrderItem::factory()->for($otherOrder)->create([
        'inventory_item_id' => $this->item->id, 'quantity' => 6, 'unit_price' => '10.01', 'line_total' => '60.06',
    ]);
    $foreignWarehouse = User::factory()->create()->personalTeam()->defaultWarehouse();
    $payload = [
        'sales_order_item_id' => $this->line->id, 'warehouse_id' => $this->warehouse->id,
        'warehouse_location_id' => $this->location->id, 'quantity' => 1,
    ];
    foreach ([
        [['sales_order_item_id' => $otherLine->id], 404],
        [['warehouse_location_id' => $foreignWarehouse->defaultLocation()->id], 404],
        [['quantity' => 6], 422],
    ] as [$invalid, $status]) {
        $this->postJson(($this->salesUrl)('reserve'), [...$payload, ...$invalid])->assertStatus($status);
    }
    $reservation = ($this->reserveSales)();
    $this->postJson(route('sales-orders.reserve', [
        'current_team' => $this->team->slug, 'salesOrder' => $otherOrder->id,
    ]), [...$payload, 'sales_order_item_id' => $otherLine->id, 'quantity' => 6])
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->postJson(route('sales-orders.fulfill', [
        'current_team' => $this->team->slug, 'salesOrder' => $otherOrder->id,
    ]), ['idempotency_key' => fake()->uuid(), 'reservation_id' => $reservation->id, 'quantity' => 1])->assertNotFound();

    expect($this->item->fresh()->quantity)->toBe(10)
        ->and($this->item->stockMovements()->count())->toBe(1);
    $this->assertDatabaseCount('stock_reservations', 1);
    $this->assertDatabaseCount('sales_fulfillments', 0);
});

test('sales orders are not exposed through a different team route', function () {
    $otherOwner = User::factory()->create();
    $otherTeam = $otherOwner->personalTeam();
    $this->actingAs($otherOwner)->get(route('sales-orders.show', [
        'current_team' => $otherTeam->slug, 'salesOrder' => $this->order->id,
    ]))->assertNotFound();
    $this->post(route('sales-orders.cancel', [
        'current_team' => $otherTeam->slug, 'salesOrder' => $this->order->id,
    ]), ['reason' => 'Wrong team'])->assertNotFound();
    $this->assertDatabaseHas('sales_orders', ['id' => $this->order->id, 'status' => 'draft']);
});

test('members can create view and cancel their own drafts but cannot perform operational mutations', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $this->team->id]);
    $member->unsetRelation('currentTeam');
    $this->actingAs($member)->get(($this->salesUrl)('show'))->assertOk();
    foreach (['reserve', 'fulfill', 'returns.store', 'refunds.store', 'cancel'] as $action) {
        $this->post(($this->salesUrl)($action), [])->assertForbidden();
    }
    $this->post(route('sales-orders.store', ['current_team' => $this->team->slug]), [
        'customer_name' => 'Member buyer', 'currency' => 'PHP',
        'items' => [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => '0.10']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $ownOrder = SalesOrder::query()->where('created_by', $member->id)->sole();
    $this->post(route('sales-orders.cancel', [
        'current_team' => $this->team->slug, 'salesOrder' => $ownOrder->id,
    ]), ['reason' => 'Own draft cancelled'])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('sales_orders', ['id' => $ownOrder->id, 'status' => 'cancelled']);

    $this->actingAs($this->owner);
    $return = ($this->returnSales)(($this->fulfillSales)());
    $this->post(($this->salesUrl)('refunds.store'), [
        'idempotency_key' => fake()->uuid(), 'sales_return_id' => $return->id,
        'amount' => '10.01', 'status' => 'pending',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($member)->patch(route('sales-orders.refunds.update', [
        'current_team' => $this->team->slug, 'salesOrder' => $this->order->id,
        'salesRefund' => SalesRefund::query()->sole()->id,
    ]), ['status' => 'refunded'])->assertForbidden();
});

test('a team admin other than the creator can fulfill return and track refunds', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $admin->update(['current_team_id' => $this->team->id]);
    $admin->unsetRelation('currentTeam');
    $this->actingAs($admin);
    $return = ($this->returnSales)(($this->fulfillSales)());
    $this->post(($this->salesUrl)('refunds.store'), [
        'idempotency_key' => fake()->uuid(), 'sales_return_id' => $return->id,
        'amount' => '10.01', 'status' => 'pending',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $refund = SalesRefund::query()->sole();
    $this->actingAs($this->owner)->patch(route('sales-orders.refunds.update', [
        'current_team' => $this->team->slug, 'salesOrder' => $this->order->id, 'salesRefund' => $refund->id,
    ]), ['status' => 'refunded'])->assertRedirect()->assertSessionHasNoErrors();
    expect($refund->fresh()->status)->toBe('refunded');
});

test('a stale deletion snapshot cannot remove returned stock images or record a false deletion', function () {
    Storage::fake('public');
    $path = 'inventory/'.$this->team->id.'/returned-carton.png';
    Storage::disk('public')->put($path, 'retained image');
    $this->item->update(['images' => [[
        'id' => fake()->uuid(), 'name' => 'returned-carton.png',
        'src' => Storage::disk('public')->url($path), 'path' => $path,
    ]]]);
    $fulfillment = ($this->fulfillSales)();
    app(RecordStockMovement::class)->handle(
        team: $this->team, inventoryItem: $this->item, warehouse: $this->warehouse,
        location: $this->location, type: StockMovementType::Issue, quantity: -5,
        reason: 'Remove remaining unreserved stock', user: $this->owner,
    );
    expect($this->item->fresh()->quantity)->toBe(0);
    ($this->returnSales)($fulfillment);

    $originalGate = Gate::getFacadeRoot();
    Gate::swap(clone $originalGate);
    $staleSnapshotApplied = false;

    try {
        /** Simulate a controller snapshot taken before the accepted return, without changing the stored stock. */
        Gate::after(function (User $user, string $ability, ?bool $result, array $arguments) use (&$staleSnapshotApplied): void {
            $item = $arguments[0] ?? null;
            if ($ability === 'delete' && $item instanceof InventoryItem && $item->id === $this->item->id) {
                $item->quantity = 0;
                $staleSnapshotApplied = true;
            }
        });
        $this->deleteJson(route('inventories.destroy', [
            'current_team' => $this->team->slug, 'inventoryItem' => $this->item->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('inventory_item');
    } finally {
        Gate::swap($originalGate);
    }

    expect($staleSnapshotApplied)->toBeTrue()
        ->and($this->item->fresh()->quantity)->toBe(1)
        ->and($this->item->fresh()->images[0]['path'])->toBe($path)
        ->and($this->item->stockLevels()->sum('quantity'))->toBe(1)
        ->and(Activity::query()->where('description', 'Inventory Deleted')->exists())->toBeFalse();
    Storage::disk('public')->assertExists($path);
    $this->assertDatabaseCount('sales_returns', 1);
});
