<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\TeamRole;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a team member can create and submit a purchase order and admins can approve and receive it', function () {
    $owner = User::factory()->create();
    $team = $owner->personalTeam();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $team->id]);
    $member->unsetRelation('currentTeam');
    $supplier = $team->suppliers()->create(['name' => 'Northwind Parts']);
    $item = createInventoryItem($team, [
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'unit_cost' => 3.25,
    ]);
    $warehouse = $team->defaultWarehouse();
    $location = $warehouse->defaultLocation();
    $indexUrl = route('purchase-orders.index', ['current_team' => $team->slug]);
    expect($supplier->team_id)->toBe($team->id)
        ->and($item->team_id)->toBe($team->id);
    $this->actingAs($member)
        ->from($indexUrl)
        ->post(route('purchase-orders.store', ['current_team' => $team->slug]), [
            'supplier_id' => $supplier->id,
            'expected_date' => now()->addWeek()->toDateString(),
            'reference' => 'SUP-QUOTE-17',
            'items' => [[
                'inventory_item_id' => $item->id,
                'quantity' => 5,
                'unit_cost' => '3.25',
            ]],
        ])
        ->assertRedirect($indexUrl)
        ->assertSessionHasNoErrors();

    $order = $team->purchaseOrders()->with('items')->sole();
    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->total_amount)->toBe('16.25')
        ->and($order->items->sole()->item_name)->toBe('Shipping carton')
        ->and($order->items->sole()->line_total)->toBe('16.25');

    $this->actingAs($member)
        ->post(route('purchase-orders.submit', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]))
        ->assertRedirect($indexUrl);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);

    $this->actingAs($member)
        ->post(route('purchase-orders.approve', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('purchase-orders.approve', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]))
        ->assertRedirect($indexUrl);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($order->fresh()->approved_by)->toBe($owner->id);

    $receiptUrl = route('purchase-orders.receive', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);
    $receiptData = [
        'purchase_order_item_id' => $order->items->sole()->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_location_id' => $location->id,
        'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb1',
    ];

    $this->actingAs($owner)
        ->post($receiptUrl, [...$receiptData, 'quantity' => 2])
        ->assertRedirect($indexUrl);

    $partialMovement = StockMovement::query()->where('inventory_item_id', $item->id)->sole();
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($order->items()->sole()->received_quantity)->toBe(2)
        ->and($item->fresh()->quantity)->toBe(2)
        ->and($partialMovement->quantity)->toBe(2)
        ->and($partialMovement->purchase_order_item_id)->toBe($order->items()->sole()->id)
        ->and($partialMovement->warehouse_id)->toBe($warehouse->id)
        ->and($partialMovement->warehouse_location_id)->toBe($location->id)
        ->and($partialMovement->user_id)->toBe($owner->id)
        ->and($partialMovement->reference)->toBe($order->number())
        ->and($partialMovement->created_at)->not->toBeNull()
        ->and(StockLevel::query()->where('inventory_item_id', $item->id)->value('quantity'))->toBe(2);

    $this->actingAs($owner)
        ->post($receiptUrl, [...$receiptData, 'quantity' => 4, 'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb2'])
        ->assertSessionHasErrors('quantity');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($order->items()->sole()->received_quantity)->toBe(2)
        ->and($item->fresh()->quantity)->toBe(2)
        ->and(StockLevel::query()->where('inventory_item_id', $item->id)->value('quantity'))->toBe(2)
        ->and(StockMovement::query()->where('inventory_item_id', $item->id)->count())->toBe(1);

    $this->actingAs($owner)
        ->post($receiptUrl, [...$receiptData, 'quantity' => 3, 'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb3'])
        ->assertRedirect($indexUrl);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->items()->sole()->received_quantity)->toBe(5)
        ->and($item->fresh()->quantity)->toBe(5)
        ->and(StockMovement::query()->where('inventory_item_id', $item->id)->where('type', 'receipt')->count())->toBe(2)
        ->and(StockMovement::query()->where('inventory_item_id', $item->id)->where('purchase_order_item_id', $order->items()->sole()->id)->count())->toBe(2)
        ->and($order->events()->count())->toBe(5);

    $this->actingAs($member)
        ->get($indexUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Index')
            ->where('canCreate', true)
            ->where('canApprove', false)
            ->has('orders', 1)
            ->where('orders.0.status', 'received')
            ->has('orders.0.events', 5));
});

test('purchase order creation validates team-owned supplier and items', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $otherTeam = User::factory()->create()->personalTeam();
    $foreignSupplier = $otherTeam->suppliers()->create(['name' => 'Private supplier']);
    $foreignItem = createInventoryItem($otherTeam, ['sku' => 'PRIVATE-1', 'name' => 'Private item']);
    $indexUrl = route('purchase-orders.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('purchase-orders.store', ['current_team' => $team->slug]), [
            'supplier_id' => $foreignSupplier->id,
            'expected_date' => now()->addWeek()->toDateString(),
            'items' => [[
                'inventory_item_id' => $foreignItem->id,
                'quantity' => 1,
                'unit_cost' => '2.00',
            ]],
        ])
        ->assertSessionHasErrors(['supplier_id', 'items.0.inventory_item_id']);

    expect($team->purchaseOrders()->exists())->toBeFalse();
});

test('admins can return received goods with an audited movement and cannot return more than received', function () {
    $owner = User::factory()->create();
    $team = $owner->personalTeam();
    $supplier = $team->suppliers()->create(['name' => 'Northwind Parts']);
    $item = createInventoryItem($team, ['sku' => 'RET-1', 'name' => 'Returnable item']);
    $warehouse = $team->defaultWarehouse();
    $location = $warehouse->defaultLocation();
    $order = $team->purchaseOrders()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $owner->id,
        'status' => PurchaseOrderStatus::Approved,
        'expected_date' => now()->addWeek()->toDateString(),
    ]);
    $line = $order->items()->create([
        'inventory_item_id' => $item->id,
        'item_name' => $item->name,
        'sku' => $item->sku,
        'quantity' => 5,
        'unit_cost' => '2.00',
        'line_total' => '10.00',
    ]);
    $indexUrl = route('purchase-orders.index', ['current_team' => $team->slug]);

    $this->actingAs($owner)
        ->from($indexUrl)
        ->post(route('purchase-orders.receive', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]), [
            'purchase_order_item_id' => $line->id,
            'warehouse_id' => $warehouse->id,
            'warehouse_location_id' => $location->id,
            'quantity' => 3,
            'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb6',
        ])
        ->assertRedirect($indexUrl);

    $returnData = [
        'purchase_order_item_id' => $line->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_location_id' => $location->id,
        'quantity' => 2,
        'reason' => 'Damaged packaging',
        'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb4',
    ];
    $returnUrl = route('purchase-orders.return', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);

    $this->actingAs($owner)
        ->from($indexUrl)
        ->post($returnUrl, [...$returnData, 'reason' => '   '])
        ->assertSessionHasErrors('reason');

    expect(StockMovement::query()->where('purchase_order_item_id', $line->id)->where('type', 'issue')->exists())->toBeFalse()
        ->and($item->fresh()->quantity)->toBe(3)
        ->and($line->fresh()->received_quantity)->toBe(3);

    $this->actingAs($owner)->from($indexUrl)->post($returnUrl, $returnData)->assertRedirect($indexUrl);
    $movement = StockMovement::query()
        ->where('purchase_order_item_id', $line->id)
        ->where('type', 'issue')
        ->sole();
    expect($movement->type->value)->toBe('issue')
        ->and($movement->quantity)->toBe(-2)
        ->and($movement->reference)->toBe($order->number())
        ->and($movement->reason)->toBe('Supplier return: Damaged packaging')
        ->and($item->fresh()->quantity)->toBe(1)
        ->and($line->fresh()->received_quantity)->toBe(3)
        ->and($order->events()->latest('id')->value('note'))->toContain('Returned 2 × Returnable item');

    $this->actingAs($owner)
        ->from($indexUrl)
        ->post($returnUrl, [...$returnData, 'quantity' => 2, 'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb5'])
        ->assertSessionHasErrors('quantity');

    expect(StockMovement::query()->where('purchase_order_item_id', $line->id)->count())->toBe(2)
        ->and(StockMovement::query()->where('purchase_order_item_id', $line->id)->where('type', 'issue')->count())->toBe(1)
        ->and($item->fresh()->quantity)->toBe(1);
});

test('a creator can cancel their own draft and the reason is retained in the order history', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $supplier = $team->suppliers()->create(['name' => 'Northwind Parts']);
    $item = createInventoryItem($team, ['sku' => 'BX-2048', 'name' => 'Shipping carton']);
    $indexUrl = route('purchase-orders.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('purchase-orders.store', ['current_team' => $team->slug]), [
            'supplier_id' => $supplier->id,
            'expected_date' => now()->addWeek()->toDateString(),
            'items' => [[
                'inventory_item_id' => $item->id,
                'quantity' => 1,
                'unit_cost' => '2.00',
            ]],
        ])
        ->assertRedirect($indexUrl)
        ->assertSessionHasNoErrors();

    $order = PurchaseOrder::query()->sole();
    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('purchase-orders.cancel', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]), [
            'reason' => '   ',
        ])
        ->assertSessionHasErrors('reason');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->fresh()->cancellation_reason)->toBeNull()
        ->and($order->events()->count())->toBe(1);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('purchase-orders.cancel', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]), [
            'reason' => 'No longer required',
        ])
        ->assertRedirect($indexUrl);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($order->fresh()->cancellation_reason)->toBe('No longer required')
        ->and($order->events()->latest('id')->value('note'))->toBe('No longer required');
});

test('a purchase order cannot be accessed from another team route', function () {
    $user = User::factory()->create();
    $foreignTeam = Team::factory()->create();
    $foreignSupplier = $foreignTeam->suppliers()->create(['name' => 'Private supplier']);
    $order = $foreignTeam->purchaseOrders()->create([
        'supplier_id' => $foreignSupplier->id,
        'status' => PurchaseOrderStatus::Draft,
        'expected_date' => now()->addWeek()->toDateString(),
    ]);

    $this->actingAs($user)
        ->post(route('purchase-orders.submit', [
            'current_team' => $user->personalTeam()->slug,
            'purchaseOrder' => $order->id,
        ]))
        ->assertNotFound();

    $replayOwner = User::factory()->create();
    $replayTeam = $replayOwner->personalTeam();
    $replaySupplier = $replayTeam->suppliers()->create(['name' => 'Replay supplier']);
    $replayOrder = $replayTeam->purchaseOrders()->create([
        'supplier_id' => $replaySupplier->id,
        'status' => PurchaseOrderStatus::PartiallyReceived,
        'expected_date' => now()->addWeek()->toDateString(),
    ]);
    $replayKey = 'e29a6315-7691-4aed-a8a4-95e3719bcdb9';
    $replayOrder->events()->create([
        'to_status' => PurchaseOrderStatus::PartiallyReceived->value,
        'idempotency_key' => $replayKey,
        'payload_hash' => str_repeat('a', 64),
    ]);

    $this->actingAs($user)
        ->post(route('purchase-orders.receive', [
            'current_team' => $user->personalTeam()->slug,
            'purchaseOrder' => $replayOrder->id,
        ]), ['idempotency_key' => $replayKey])
        ->assertNotFound();
});

test('the purchase order list remains available after a supplier is archived', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $supplier = $team->suppliers()->create(['name' => 'Archived supplier name']);
    $order = $team->purchaseOrders()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $user->id,
        'status' => PurchaseOrderStatus::Draft,
        'expected_date' => now()->addWeek()->toDateString(),
    ]);
    $supplier->delete();

    $this->actingAs($user)
        ->get(route('purchase-orders.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Index')
            ->has('orders', 1)
            ->where('orders.0.id', $order->id)
            ->where('orders.0.supplierName', 'Archived supplier name'));
});

test('replaying a receipt or supplier return does not repeat its stock movement', function () {
    $owner = User::factory()->create();
    $team = $owner->personalTeam();
    $supplier = $team->suppliers()->create(['name' => 'Northwind Parts']);
    $item = createInventoryItem($team, ['sku' => 'REPLAY-1', 'name' => 'Replay item']);
    $warehouse = $team->defaultWarehouse();
    $location = $warehouse->defaultLocation();
    $order = $team->purchaseOrders()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $owner->id,
        'status' => PurchaseOrderStatus::Approved,
        'expected_date' => now()->addWeek()->toDateString(),
    ]);
    $line = $order->items()->create([
        'inventory_item_id' => $item->id,
        'item_name' => $item->name,
        'sku' => $item->sku,
        'quantity' => 8,
        'unit_cost' => '2.00',
        'line_total' => '16.00',
    ]);
    $receiveUrl = route('purchase-orders.receive', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);
    $receiveData = [
        'purchase_order_item_id' => $line->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_location_id' => $location->id,
        'quantity' => 2,
        'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdbb',
    ];

    $this->actingAs($owner)->post($receiveUrl, [...$receiveData, 'idempotency_key' => strtoupper($receiveData['idempotency_key'])])->assertRedirect();
    $this->actingAs($owner)->post($receiveUrl, $receiveData)->assertRedirect();

    expect($line->fresh()->received_quantity)->toBe(2)
        ->and($item->fresh()->quantity)->toBe(2)
        ->and(StockLevel::query()->where('inventory_item_id', $item->id)->where('warehouse_location_id', $location->id)->value('quantity'))->toBe(2)
        ->and(StockMovement::query()->where('purchase_order_item_id', $line->id)->count())->toBe(1);

    $this->actingAs($owner)
        ->post($receiveUrl, [...$receiveData, 'quantity' => 3])
        ->assertSessionHasErrors('idempotency_key');

    $this->actingAs($owner)
        ->post(route('purchase-orders.return', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]), [
            ...$receiveData,
            'quantity' => 1,
            'reason' => 'Different operation',
        ])
        ->assertSessionHasErrors('idempotency_key');

    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $team->id]);
    $member->unsetRelation('currentTeam');
    $this->actingAs($member)->post($receiveUrl, $receiveData)->assertForbidden();

    $retryKey = 'e29a6315-7691-4aed-a8a4-95e3719bcdb0';
    $this->actingAs($owner)
        ->post($receiveUrl, [...$receiveData, 'quantity' => 9, 'idempotency_key' => $retryKey])
        ->assertSessionHasErrors('quantity');
    expect($line->fresh()->received_quantity)->toBe(2)
        ->and($item->fresh()->quantity)->toBe(2)
        ->and(StockMovement::query()->where('purchase_order_item_id', $line->id)->count())->toBe(1)
        ->and($order->events()->count())->toBe(1);
    $this->actingAs($owner)
        ->post($receiveUrl, [...$receiveData, 'quantity' => 1, 'idempotency_key' => $retryKey])
        ->assertRedirect();

    $freshReceipt = [...$receiveData, 'quantity' => 2, 'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb7'];
    $this->actingAs($owner)->post($receiveUrl, $freshReceipt)->assertRedirect();
    $finalReceipt = [...$receiveData, 'quantity' => 3, 'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdb8'];
    $this->actingAs($owner)->post($receiveUrl, $finalReceipt)->assertRedirect();
    $this->actingAs($owner)->post($receiveUrl, $finalReceipt)->assertRedirect();

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($line->fresh()->received_quantity)->toBe(8)
        ->and($item->fresh()->quantity)->toBe(8)
        ->and(StockLevel::query()->where('inventory_item_id', $item->id)->where('warehouse_location_id', $location->id)->value('quantity'))->toBe(8)
        ->and(StockMovement::query()->where('purchase_order_item_id', $line->id)->count())->toBe(4)
        ->and($order->events()->count())->toBe(4);

    $returnUrl = route('purchase-orders.return', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);
    $returnData = [
        ...$receiveData,
        'quantity' => 1,
        'reason' => ' Damaged ',
        'idempotency_key' => 'e29a6315-7691-4aed-a8a4-95e3719bcdbc',
    ];

    $this->actingAs($owner)->post($returnUrl, $returnData)->assertRedirect();
    $this->actingAs($owner)->post($returnUrl, [...$returnData, 'reason' => 'Damaged'])->assertRedirect();
    $this->actingAs($owner)
        ->post($returnUrl, [...$returnData, 'quantity' => 2])
        ->assertSessionHasErrors('idempotency_key');
    $this->actingAs($member)->post($returnUrl, $returnData)->assertForbidden();

    $this->actingAs($owner)
        ->post($receiveUrl, [...$receiveData, 'idempotency_key' => 'not-a-uuid'])
        ->assertSessionHasErrors('idempotency_key');
    $this->actingAs($owner)
        ->post($receiveUrl, [...$receiveData, 'idempotency_key' => ['malformed']])
        ->assertSessionHasErrors('idempotency_key');
    $this->actingAs($owner)
        ->post($receiveUrl, [...$receiveData, 'idempotency_key' => null])
        ->assertSessionHasErrors('idempotency_key');
    $this->actingAs($owner)
        ->post($returnUrl, [...$returnData, 'idempotency_key' => 'invalid-return-key'])
        ->assertSessionHasErrors('idempotency_key');

    expect($line->fresh()->received_quantity)->toBe(8)
        ->and($item->fresh()->quantity)->toBe(7)
        ->and(StockLevel::query()->where('inventory_item_id', $item->id)->where('warehouse_location_id', $location->id)->value('quantity'))->toBe(7)
        ->and(StockMovement::query()->where('purchase_order_item_id', $line->id)->count())->toBe(5)
        ->and($order->events()->count())->toBe(5);
});

test('a draft creator can replace purchase order lines and the total is recalculated', function () {
    $creator = User::factory()->create();
    $team = $creator->personalTeam();
    $supplier = $team->suppliers()->create(['name' => 'Northwind Parts']);
    $originalItem = createInventoryItem($team, ['sku' => 'EDIT-OLD', 'name' => 'Old item']);
    $replacementItem = createInventoryItem($team, ['sku' => 'EDIT-NEW', 'name' => 'Replacement item']);
    $order = $team->purchaseOrders()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $creator->id,
        'status' => PurchaseOrderStatus::Draft,
        'expected_date' => now()->addWeek()->toDateString(),
        'reference' => 'OLD-QUOTE',
        'total_amount' => '10.00',
    ]);
    $order->items()->create([
        'inventory_item_id' => $originalItem->id,
        'item_name' => $originalItem->name,
        'sku' => $originalItem->sku,
        'quantity' => 2,
        'unit_cost' => '5.00',
        'line_total' => '10.00',
    ]);
    $indexUrl = route('purchase-orders.index', ['current_team' => $team->slug]);

    $this->actingAs($creator)
        ->from($indexUrl)
        ->patch(route('purchase-orders.update', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]), [
            'supplier_id' => $supplier->id,
            'expected_date' => now()->addWeeks(2)->toDateString(),
            'reference' => 'NEW-QUOTE',
            'items' => [[
                'inventory_item_id' => $replacementItem->id,
                'quantity' => 3,
                'unit_cost' => '7.25',
            ]],
        ])
        ->assertRedirect($indexUrl)
        ->assertSessionHasNoErrors();

    $updatedOrder = $order->fresh();
    expect($updatedOrder->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($updatedOrder->reference)->toBe('NEW-QUOTE')
        ->and($updatedOrder->expected_date->toDateString())->toBe(now()->addWeeks(2)->toDateString())
        ->and($updatedOrder->total_amount)->toBe('21.75')
        ->and($updatedOrder->items)->toHaveCount(1)
        ->and($updatedOrder->items->sole()->inventory_item_id)->toBe($replacementItem->id)
        ->and($updatedOrder->items->sole()->item_name)->toBe('Replacement item')
        ->and($updatedOrder->items->sole()->line_total)->toBe('21.75')
        ->and($updatedOrder->events()->latest('id')->value('note'))->toBe('Draft purchase order updated.');
});

test('draft updates reject foreign suppliers, foreign items, and duplicate items without changing the order', function () {
    $creator = User::factory()->create();
    $team = $creator->personalTeam();
    $otherTeam = User::factory()->create()->personalTeam();
    $supplier = $team->suppliers()->create(['name' => 'Team supplier']);
    $foreignSupplier = $otherTeam->suppliers()->create(['name' => 'Private supplier']);
    $item = createInventoryItem($team, ['sku' => 'EDIT-VALID', 'name' => 'Valid item']);
    $foreignItem = createInventoryItem($otherTeam, ['sku' => 'EDIT-PRIVATE', 'name' => 'Private item']);
    $order = $team->purchaseOrders()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $creator->id,
        'status' => PurchaseOrderStatus::Draft,
        'expected_date' => now()->addWeek()->toDateString(),
        'reference' => 'UNCHANGED',
        'total_amount' => '2.00',
    ]);
    $line = $order->items()->create([
        'inventory_item_id' => $item->id,
        'item_name' => $item->name,
        'sku' => $item->sku,
        'quantity' => 1,
        'unit_cost' => '2.00',
        'line_total' => '2.00',
    ]);
    $updateUrl = route('purchase-orders.update', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);
    $validDraft = [
        'supplier_id' => $supplier->id,
        'expected_date' => now()->addWeek()->toDateString(),
        'reference' => 'CHANGED',
        'items' => [['inventory_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => '2.00']],
    ];

    $this->actingAs($creator)->patch($updateUrl, [...$validDraft, 'supplier_id' => $foreignSupplier->id])
        ->assertSessionHasErrors('supplier_id');
    $this->actingAs($creator)->patch($updateUrl, [...$validDraft, 'items' => [[
        'inventory_item_id' => $foreignItem->id,
        'quantity' => 1,
        'unit_cost' => '2.00',
    ]]])->assertSessionHasErrors('items.0.inventory_item_id');
    $this->actingAs($creator)->patch($updateUrl, [...$validDraft, 'items' => [
        ['inventory_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => '2.00'],
        ['inventory_item_id' => $item->id, 'quantity' => 2, 'unit_cost' => '2.00'],
    ]])->assertSessionHasErrors('items.1.inventory_item_id');

    expect($order->fresh()->reference)->toBe('UNCHANGED')
        ->and($order->fresh()->total_amount)->toBe('2.00')
        ->and($order->items()->count())->toBe(1)
        ->and($order->items()->sole()->id)->toBe($line->id)
        ->and($order->events()->count())->toBe(0);
});

test('only a draft creator or team owner can edit a draft and submitted orders cannot be edited', function () {
    $owner = User::factory()->create();
    $team = $owner->personalTeam();
    $creator = User::factory()->create();
    $otherMember = User::factory()->create();
    $team->members()->attach($creator, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $creator->update(['current_team_id' => $team->id]);
    $otherMember->update(['current_team_id' => $team->id]);
    $creator->unsetRelation('currentTeam');
    $otherMember->unsetRelation('currentTeam');
    $supplier = $team->suppliers()->create(['name' => 'Northwind Parts']);
    $item = createInventoryItem($team, ['sku' => 'EDIT-AUTH', 'name' => 'Editable item']);
    $order = $team->purchaseOrders()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $creator->id,
        'status' => PurchaseOrderStatus::Draft,
        'expected_date' => now()->addWeek()->toDateString(),
        'total_amount' => '2.00',
    ]);
    $order->items()->create([
        'inventory_item_id' => $item->id,
        'item_name' => $item->name,
        'sku' => $item->sku,
        'quantity' => 1,
        'unit_cost' => '2.00',
        'line_total' => '2.00',
    ]);
    $updateUrl = route('purchase-orders.update', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);
    $payload = [
        'supplier_id' => $supplier->id,
        'expected_date' => now()->addWeek()->toDateString(),
        'reference' => 'AUTHORIZED',
        'items' => [['inventory_item_id' => $item->id, 'quantity' => 2, 'unit_cost' => '2.00']],
    ];

    $this->actingAs($otherMember)->patch($updateUrl, $payload)->assertForbidden();
    $this->actingAs($owner)->patch($updateUrl, $payload)->assertRedirect();
    expect($order->fresh()->reference)->toBe('AUTHORIZED');

    $order->update(['status' => PurchaseOrderStatus::Submitted]);
    $this->actingAs($creator)->patch($updateUrl, [...$payload, 'reference' => 'MUST-NOT-CHANGE'])->assertForbidden();
    expect($order->fresh()->reference)->toBe('AUTHORIZED');
});

test('an owner can request revisions to a submitted order and its creator can edit and resubmit it', function () {
    $owner = User::factory()->create();
    $team = $owner->personalTeam();
    $creator = User::factory()->create();
    $team->members()->attach($creator, ['role' => TeamRole::Member->value]);
    $creator->update(['current_team_id' => $team->id]);
    $creator->unsetRelation('currentTeam');
    $supplier = $team->suppliers()->create(['name' => 'Northwind Parts']);
    $item = createInventoryItem($team, ['sku' => 'REVISION-1', 'name' => 'Revision item']);
    $order = $team->purchaseOrders()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $creator->id,
        'approved_by' => $owner->id,
        'status' => PurchaseOrderStatus::Submitted,
        'expected_date' => now()->addWeek()->toDateString(),
        'total_amount' => '2.00',
    ]);
    $order->items()->create([
        'inventory_item_id' => $item->id,
        'item_name' => $item->name,
        'sku' => $item->sku,
        'quantity' => 1,
        'unit_cost' => '2.00',
        'line_total' => '2.00',
    ]);
    $requestRevisionUrl = route('purchase-orders.request-revision', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);

    $this->actingAs($creator)->post($requestRevisionUrl, ['reason' => 'Please clarify the requested quantity.'])->assertForbidden();
    $this->actingAs($owner)->post($requestRevisionUrl, ['reason' => '   '])->assertSessionHasErrors('reason');
    $this->actingAs($owner)->post($requestRevisionUrl, ['reason' => 'Please clarify the requested quantity.'])->assertRedirect();

    $revisionEvent = $order->events()->latest('id')->firstOrFail();
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->fresh()->approved_by)->toBeNull()
        ->and($revisionEvent->from_status)->toBe(PurchaseOrderStatus::Submitted->value)
        ->and($revisionEvent->to_status)->toBe(PurchaseOrderStatus::Draft->value)
        ->and($revisionEvent->note)->toBe('Please clarify the requested quantity.');

    $indexUrl = route('purchase-orders.index', ['current_team' => $team->slug]);
    $this->actingAs($creator)
        ->from($indexUrl)
        ->patch(route('purchase-orders.update', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]), [
            'supplier_id' => $supplier->id,
            'expected_date' => now()->addWeek()->toDateString(),
            'reference' => 'REVISED-QUOTE',
            'items' => [['inventory_item_id' => $item->id, 'quantity' => 4, 'unit_cost' => '2.00']],
        ])
        ->assertRedirect($indexUrl);
    $this->actingAs($creator)
        ->from($indexUrl)
        ->post(route('purchase-orders.submit', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]))
        ->assertRedirect($indexUrl);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted)
        ->and($order->fresh()->reference)->toBe('REVISED-QUOTE')
        ->and($order->items()->sole()->quantity)->toBe(4)
        ->and($order->events()->count())->toBe(3);
});

test('purchase order search is team scoped and pagination metadata stays separate from the orders array', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $supplier = $team->suppliers()->create(['name' => 'Searchable Supply Co']);
    $matchingOrder = null;

    for ($index = 1; $index <= 21; $index++) {
        $order = $team->purchaseOrders()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => $index === 1 ? PurchaseOrderStatus::Submitted : PurchaseOrderStatus::Draft,
            'expected_date' => now()->addWeek()->toDateString(),
            'reference' => $index === 1 ? 'FIND-ME-REFERENCE' : "PAGE-{$index}",
        ]);
        if ($index === 1) {
            $matchingOrder = $order;
        }
    }
    $otherTeam = User::factory()->create()->personalTeam();
    $otherSupplier = $otherTeam->suppliers()->create(['name' => 'Searchable Supply Co']);
    $otherTeam->purchaseOrders()->create([
        'supplier_id' => $otherSupplier->id,
        'status' => PurchaseOrderStatus::Draft,
        'expected_date' => now()->addWeek()->toDateString(),
        'reference' => 'FIND-ME-REFERENCE',
    ]);

    $this->actingAs($user)
        ->get(route('purchase-orders.index', ['current_team' => $team->slug, 'search' => 'FIND-ME-REFERENCE', 'status' => 'submitted']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Index')
            ->where('filters.search', 'FIND-ME-REFERENCE')
            ->where('filters.status', 'submitted')
            ->where('orders.0.id', $matchingOrder->id)
            ->where('pagination.total', 1)
            ->where('pagination.currentPage', 1));

    $this->actingAs($user)
        ->get(route('purchase-orders.index', ['current_team' => $team->slug, 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Index')
            ->has('orders', 1)
            ->where('pagination.currentPage', 2)
            ->where('pagination.lastPage', 2)
            ->where('pagination.total', 21)
            ->where('pagination.from', 21)
            ->where('pagination.to', 21)
            ->where('pagination.prevPageUrl', fn ($url) => str_contains($url, 'page=1'))
            ->where('pagination.nextPageUrl', null));

    $this->actingAs($user)
        ->get(route('purchase-orders.index', ['current_team' => $team->slug, 'search' => $matchingOrder->number()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Index')
            ->has('orders', 1)
            ->where('orders.0.id', $matchingOrder->id));
});
