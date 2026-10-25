<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\TeamRole;
use App\Models\PurchaseOrder;
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
    ];

    $this->actingAs($owner)
        ->post($receiptUrl, [...$receiptData, 'quantity' => 2])
        ->assertRedirect($indexUrl);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($order->items()->sole()->received_quantity)->toBe(2)
        ->and($item->fresh()->quantity)->toBe(2);

    $this->actingAs($owner)
        ->post($receiptUrl, [...$receiptData, 'quantity' => 4])
        ->assertSessionHasErrors('quantity');

    expect($order->items()->sole()->received_quantity)->toBe(2)
        ->and($item->fresh()->quantity)->toBe(2);

    $this->actingAs($owner)
        ->post($receiptUrl, [...$receiptData, 'quantity' => 3])
        ->assertRedirect($indexUrl);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->items()->sole()->received_quantity)->toBe(5)
        ->and($item->fresh()->quantity)->toBe(5)
        ->and(StockMovement::query()->where('inventory_item_id', $item->id)->where('type', 'receipt')->count())->toBe(2)
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
        ])
        ->assertRedirect($indexUrl);

    $returnData = [
        'purchase_order_item_id' => $line->id,
        'warehouse_id' => $warehouse->id,
        'warehouse_location_id' => $location->id,
        'quantity' => 2,
        'reason' => 'Damaged packaging',
    ];
    $returnUrl = route('purchase-orders.return', ['current_team' => $team->slug, 'purchaseOrder' => $order->id]);

    $this->actingAs($owner)->from($indexUrl)->post($returnUrl, $returnData)->assertRedirect($indexUrl);
    $movement = StockMovement::query()->where('purchase_order_item_id', $line->id)->sole();
    expect($movement->type->value)->toBe('issue')
        ->and($movement->quantity)->toBe(-2)
        ->and($movement->reference)->toBe($order->number())
        ->and($movement->reason)->toBe('Supplier return: Damaged packaging')
        ->and($item->fresh()->quantity)->toBe(1)
        ->and($line->fresh()->received_quantity)->toBe(3)
        ->and($order->events()->latest('id')->value('note'))->toContain('Returned 2 × Returnable item');

    $this->actingAs($owner)
        ->from($indexUrl)
        ->post($returnUrl, [...$returnData, 'quantity' => 2])
        ->assertSessionHasErrors('quantity');

    expect(StockMovement::query()->where('purchase_order_item_id', $line->id)->count())->toBe(1)
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
