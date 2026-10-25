<?php

namespace App\Http\Controllers\Purchases;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    /**
     * Display the team's purchase orders and the options for creating and receiving them.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewAny', PurchaseOrder::class);

        return Inertia::render('purchase-orders/Index', [
            'orders' => $team->purchaseOrders()
                ->with(['supplier', 'creator', 'approver', 'items', 'events.user'])
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (PurchaseOrder $order): array => $this->orderToArray($order))
                ->values(),
            'suppliers' => $team->suppliers()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $supplier): array => ['id' => $supplier->id, 'name' => $supplier->name]),
            'items' => $team->inventoryItems()
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_cost'])
                ->map(fn (InventoryItem $item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'unitCost' => (float) $item->unit_cost,
                ]),
            'warehouses' => $team->warehouses()
                ->with(['locations' => fn ($query) => $query->orderByDesc('is_default')->orderBy('name')])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (Warehouse $warehouse): array => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'locations' => $warehouse->locations->map(fn ($location): array => [
                        'id' => $location->id,
                        'name' => $location->name,
                    ])->values(),
                ]),
            'canCreate' => $request->user()->can('create', PurchaseOrder::class),
            'canApprove' => $request->user()->can('approveAny', PurchaseOrder::class),
            'canReceive' => $request->user()->can('receiveAny', PurchaseOrder::class),
        ]);
    }

    /**
     * Create a draft purchase order with immutable item snapshots.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('create', PurchaseOrder::class);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)->whereNull('deleted_at')->where('is_active', true)],
            'expected_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->where('team_id', $team->id)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ]);

        DB::transaction(function () use ($team, $request, $validated): void {
            $items = $team->inventoryItems()
                ->whereIn('id', collect($validated['items'])->pluck('inventory_item_id'))
                ->get()
                ->keyBy('id');
            $totalCents = 0;

            foreach ($validated['items'] as $line) {
                $totalCents += (int) round((float) $line['unit_cost'] * 100) * (int) $line['quantity'];
            }

            if ($totalCents > 99999999999999) {
                throw ValidationException::withMessages([
                    'items' => 'The total purchase order amount exceeds the supported limit.',
                ]);
            }

            $order = $team->purchaseOrders()->create([
                'supplier_id' => $validated['supplier_id'],
                'created_by' => $request->user()->id,
                'status' => PurchaseOrderStatus::Draft,
                'expected_date' => $validated['expected_date'],
                'reference' => ($validated['reference'] ?? '') ?: null,
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            ]);

            foreach ($validated['items'] as $line) {
                $item = $items->get((int) $line['inventory_item_id']);
                abort_unless($item instanceof InventoryItem, 422);
                $unitCostCents = (int) round((float) $line['unit_cost'] * 100);
                $lineTotalCents = $unitCostCents * (int) $line['quantity'];

                $order->items()->create([
                    'inventory_item_id' => $item->id,
                    'item_name' => $item->name,
                    'sku' => $item->sku,
                    'quantity' => $line['quantity'],
                    'unit_cost' => number_format($unitCostCents / 100, 2, '.', ''),
                    'line_total' => number_format($lineTotalCents / 100, 2, '.', ''),
                ]);
            }

            $order->events()->create([
                'user_id' => $request->user()->id,
                'to_status' => PurchaseOrderStatus::Draft->value,
                'note' => 'Purchase order created.',
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Purchase order draft created.']);

        return back();
    }

    /**
     * Submit a draft purchase order for approval.
     */
    public function submit(Request $request, string $currentTeamSlug, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('submit', $order);

        DB::transaction(function () use ($order, $request): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('submit', $lockedOrder);
            $this->transition($lockedOrder, PurchaseOrderStatus::Submitted, $request->user()->id, 'Submitted for approval.');
        });

        return back();
    }

    /**
     * Approve a submitted order. Only team owners and admins may approve orders.
     */
    public function approve(Request $request, string $currentTeamSlug, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('approve', $order);

        DB::transaction(function () use ($order, $request): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('approve', $lockedOrder);
            $lockedOrder->update(['approved_by' => $request->user()->id]);
            $this->transition($lockedOrder, PurchaseOrderStatus::Approved, $request->user()->id, 'Purchase order approved.');
        });

        return back();
    }

    /**
     * Receive part or all of one approved order line into a team warehouse bin.
     */
    public function receive(
        Request $request,
        string $currentTeamSlug,
        PurchaseOrder $purchaseOrder,
        RecordStockMovement $recordStockMovement,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('receive', $order);

        $validated = $request->validate([
            'purchase_order_item_id' => ['required', 'integer', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $order->id)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('team_id', $team->id)],
            'warehouse_location_id' => ['required', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', (int) $request->input('warehouse_id'))],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        DB::transaction(function () use ($order, $team, $request, $validated, $recordStockMovement): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedOrder->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'Only approved orders can be received.']);
            }

            $line = $lockedOrder->items()->whereKey($validated['purchase_order_item_id'])->lockForUpdate()->firstOrFail();
            $quantity = (int) $validated['quantity'];
            $remainingQuantity = $line->quantity - $line->received_quantity;

            if ($quantity > $remainingQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$remainingQuantity} unit(s) remain on this order line.",
                ]);
            }

            $inventoryItem = $line->inventory_item_id
                ? $team->inventoryItems()->find($line->inventory_item_id)
                : null;
            if (! $inventoryItem instanceof InventoryItem) {
                throw ValidationException::withMessages([
                    'purchase_order_item_id' => 'The inventory item for this order line is no longer available.',
                ]);
            }

            $warehouse = $team->warehouses()->findOrFail($validated['warehouse_id']);
            $location = $warehouse->locations()->findOrFail($validated['warehouse_location_id']);

            $recordStockMovement->handle(
                team: $team,
                inventoryItem: $inventoryItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Receipt,
                quantity: $quantity,
                unitCost: (float) $line->unit_cost,
                reference: $lockedOrder->number(),
                reason: 'Received against purchase order '.$lockedOrder->number(),
                user: $request->user(),
            );

            $line->increment('received_quantity', $quantity);
            $allReceived = ! $lockedOrder->items()
                ->whereColumn('received_quantity', '<', 'quantity')
                ->exists();
            $nextStatus = $allReceived ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived;
            $previousStatus = $lockedOrder->status;

            if ($previousStatus !== $nextStatus) {
                $lockedOrder->update(['status' => $nextStatus]);
            }

            $lockedOrder->events()->create([
                'user_id' => $request->user()->id,
                'from_status' => $previousStatus->value,
                'to_status' => $nextStatus->value,
                'note' => "Received {$quantity} × {$line->item_name} ({$line->sku}).",
            ]);
        });

        return back();
    }

    /**
     * Cancel an order that has not been fully received and retain the reason.
     */
    public function returnGoods(
        Request $request,
        string $currentTeamSlug,
        PurchaseOrder $purchaseOrder,
        RecordStockMovement $recordStockMovement,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('returnGoods', $order);

        $validated = $request->validate([
            'purchase_order_item_id' => ['required', 'integer', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $order->id)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('team_id', $team->id)],
            'warehouse_location_id' => ['required', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', (int) $request->input('warehouse_id'))],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($order, $team, $request, $validated, $recordStockMovement): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('returnGoods', $lockedOrder);
            $line = $lockedOrder->items()->whereKey($validated['purchase_order_item_id'])->lockForUpdate()->firstOrFail();
            $returnedQuantity = (int) StockMovement::query()
                ->where('purchase_order_item_id', $line->id)
                ->where('type', StockMovementType::Issue)
                ->sum(DB::raw('ABS(quantity)'));
            $returnableQuantity = $line->received_quantity - $returnedQuantity;
            $quantity = (int) $validated['quantity'];

            if ($quantity > $returnableQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$returnableQuantity} unit(s) can be returned.",
                ]);
            }

            $inventoryItem = $line->inventory_item_id
                ? $team->inventoryItems()->find($line->inventory_item_id)
                : null;
            if (! $inventoryItem instanceof InventoryItem) {
                throw ValidationException::withMessages([
                    'purchase_order_item_id' => 'The inventory item for this order line is no longer available.',
                ]);
            }

            $warehouse = $team->warehouses()->findOrFail($validated['warehouse_id']);
            $location = $warehouse->locations()->findOrFail($validated['warehouse_location_id']);
            $movement = $recordStockMovement->handle(
                team: $team,
                inventoryItem: $inventoryItem,
                warehouse: $warehouse,
                location: $location,
                type: StockMovementType::Issue,
                quantity: -$quantity,
                unitCost: (float) $line->unit_cost,
                reference: $lockedOrder->number(),
                reason: 'Supplier return: '.trim($validated['reason']),
                user: $request->user(),
            );
            $movement->update(['purchase_order_item_id' => $line->id]);

            $lockedOrder->events()->create([
                'user_id' => $request->user()->id,
                'from_status' => $lockedOrder->status->value,
                'to_status' => $lockedOrder->status->value,
                'note' => "Returned {$quantity} × {$line->item_name} ({$line->sku}) to supplier: ".trim($validated['reason']),
            ]);
        });

        return back();
    }

    public function cancel(Request $request, string $currentTeamSlug, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('cancel', $order);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($order, $request, $validated): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('cancel', $lockedOrder);
            if (in_array($lockedOrder->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'This purchase order can no longer be cancelled.']);
            }

            $reason = trim($validated['reason']);
            $lockedOrder->update(['cancellation_reason' => $reason]);
            $this->transition($lockedOrder, PurchaseOrderStatus::Cancelled, $request->user()->id, $reason);
        });

        return back();
    }

    private function teamOrder(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $request->user()->currentTeam->purchaseOrders()->findOrFail($purchaseOrder->id);
    }

    private function transition(PurchaseOrder $order, PurchaseOrderStatus $status, int $userId, string $note): void
    {
        $fromStatus = $order->status;
        $order->update(['status' => $status]);
        $order->events()->create([
            'user_id' => $userId,
            'from_status' => $fromStatus->value,
            'to_status' => $status->value,
            'note' => $note,
        ]);
    }

    /**
     * Convert a purchase order and its history to the Inertia response shape.
     *
     * @return array<string, mixed>
     */
    private function orderToArray(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number(),
            'supplierName' => $order->supplier?->name ?? 'Archived supplier',
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'expectedDate' => $order->expected_date->toDateString(),
            'reference' => $order->reference,
            'totalAmount' => (float) $order->total_amount,
            'createdBy' => $order->creator?->name ?? 'Former user',
            'createdById' => $order->created_by,
            'cancellationReason' => $order->cancellation_reason,
            'items' => $order->items->map(fn (PurchaseOrderItem $item): array => [
                'id' => $item->id,
                'inventoryItemId' => $item->inventory_item_id,
                'name' => $item->item_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'receivedQuantity' => $item->received_quantity,
                'returnedQuantity' => (int) StockMovement::query()
                    ->where('purchase_order_item_id', $item->id)
                    ->where('type', StockMovementType::Issue)
                    ->sum(DB::raw('ABS(quantity)')),
                'unitCost' => (float) $item->unit_cost,
                'lineTotal' => (float) $item->line_total,
            ])->values(),
            'events' => $order->events->map(fn ($event): array => [
                'id' => $event->id,
                'fromStatus' => $event->from_status,
                'toStatus' => $event->to_status,
                'note' => $event->note,
                'userName' => $event->user?->name ?? 'Former user',
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
