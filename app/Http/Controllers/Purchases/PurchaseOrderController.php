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
use App\Models\Team;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        $validatedFilters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($validatedFilters['search'] ?? '');
        $status = $validatedFilters['status'] ?? '';
        $ordersQuery = $team->purchaseOrders()
            ->with(['team', 'supplier', 'creator', 'approver', 'items', 'events.user'])
            ->latest('id');

        if ($status !== '') {
            $ordersQuery->where('status', $status);
        }

        if ($search !== '') {
            $numberId = preg_match('/^PO-0*(\d+)$/i', $search, $matches) === 1
                ? (int) $matches[1]
                : null;
            $ordersQuery->where(function (Builder $query) use ($search, $numberId): void {
                $query->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn (Builder $supplierQuery) => $supplierQuery->where('name', 'like', "%{$search}%"));

                if ($numberId !== null) {
                    $query->orWhereKey($numberId);
                }
            });
        }

        $ordersPage = $ordersQuery->paginate(20)->withQueryString();
        $itemIds = $ordersPage->getCollection()->flatMap(fn (PurchaseOrder $order) => $order->items->pluck('id'))->all();
        $returnedQuantities = $itemIds === [] ? collect() : StockMovement::query()
            ->whereIn('purchase_order_item_id', $itemIds)
            ->where('type', StockMovementType::Issue)
            ->select('purchase_order_item_id', DB::raw('SUM(ABS(quantity)) as returned_quantity'))
            ->groupBy('purchase_order_item_id')
            ->pluck('returned_quantity', 'purchase_order_item_id');

        $orders = $ordersPage->getCollection()
            ->map(fn (PurchaseOrder $order): array => $this->orderToArray($order, $returnedQuantities->all(), $request))
            ->values()
            ->all();

        return Inertia::render('purchase-orders/Index', [
            'orders' => $orders,
            'pagination' => [
                'currentPage' => $ordersPage->currentPage(),
                'lastPage' => $ordersPage->lastPage(),
                'total' => $ordersPage->total(),
                'from' => $ordersPage->firstItem(),
                'to' => $ordersPage->lastItem(),
                'prevPageUrl' => $ordersPage->previousPageUrl(),
                'nextPageUrl' => $ordersPage->nextPageUrl(),
            ],
            'filters' => ['search' => $search, 'status' => $status],
            'statusOptions' => collect(PurchaseOrderStatus::cases())->map(fn (PurchaseOrderStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])->values(),
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

        $validated = $this->validatedDraft($request, $team->id);

        DB::transaction(function () use ($team, $request, $validated): void {
            [$items, $totalCents] = $this->draftSnapshot($team->id, $validated);

            $order = $team->purchaseOrders()->create([
                'supplier_id' => $validated['supplier_id'],
                'created_by' => $request->user()->id,
                'status' => PurchaseOrderStatus::Draft,
                'expected_date' => $validated['expected_date'],
                'reference' => ($validated['reference'] ?? '') ?: null,
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            ]);

            $this->createItemSnapshots($order, $items, $validated['items']);

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
     * Replace the item snapshots on an editable draft purchase order.
     */
    public function update(Request $request, string $currentTeamSlug, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('update', $order);
        $team = $request->user()->currentTeam;
        $validated = $this->validatedDraft($request, $team->id);

        DB::transaction(function () use ($order, $request, $team, $validated): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $lockedOrder);
            [$items, $totalCents] = $this->draftSnapshot($team->id, $validated);

            $lockedOrder->update([
                'supplier_id' => $validated['supplier_id'],
                'expected_date' => $validated['expected_date'],
                'reference' => ($validated['reference'] ?? '') ?: null,
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            ]);
            $lockedOrder->items()->delete();
            $this->createItemSnapshots($lockedOrder, $items, $validated['items']);
            $lockedOrder->events()->create([
                'user_id' => $request->user()->id,
                'from_status' => PurchaseOrderStatus::Draft->value,
                'to_status' => PurchaseOrderStatus::Draft->value,
                'note' => 'Draft purchase order updated.',
            ]);
        });

        return back();
    }

    /**
     * Return a submitted purchase order to its creator for revisions.
     */
    public function requestRevision(Request $request, string $currentTeamSlug, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('requestRevision', $order);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $reason = trim($validated['reason']);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A revision reason is required.']);
        }

        DB::transaction(function () use ($order, $request, $reason): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('requestRevision', $lockedOrder);
            $lockedOrder->update(['approved_by' => null]);
            $this->transition($lockedOrder, PurchaseOrderStatus::Draft, $request->user()->id, $reason);
        });

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
        Gate::authorize('receiveAny', PurchaseOrder::class);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'purchase_order_item_id' => ['required', 'integer', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $order->id)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('team_id', $team->id)],
            'warehouse_location_id' => ['required', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', (int) $request->input('warehouse_id'))],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $validated['idempotency_key'] = strtolower($validated['idempotency_key']);
        $payloadHash = $this->operationPayloadHash('receive', $validated);

        DB::transaction(function () use ($order, $team, $request, $validated, $recordStockMovement, $payloadHash): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($this->isIdempotentReplay($lockedOrder, $validated['idempotency_key'], $payloadHash)) {
                return;
            }

            if (! in_array($lockedOrder->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'Only approved orders can be received.']);
            }
            Gate::authorize('receive', $lockedOrder);

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

            $warehouse = $team->warehouses()->whereKey($validated['warehouse_id'])->firstOrFail();
            $location = $warehouse->locations()->whereKey($validated['warehouse_location_id'])->firstOrFail();

            $movement = $recordStockMovement->handle(
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
            $movement->update(['purchase_order_item_id' => $line->id]);

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
                'idempotency_key' => $validated['idempotency_key'],
                'payload_hash' => $payloadHash,
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
        Gate::authorize('receiveAny', PurchaseOrder::class);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'purchase_order_item_id' => ['required', 'integer', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $order->id)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('team_id', $team->id)],
            'warehouse_location_id' => ['required', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', (int) $request->input('warehouse_id'))],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $validated['idempotency_key'] = strtolower($validated['idempotency_key']);
        $validated['reason'] = trim($validated['reason']);

        if ($validated['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'A return reason is required.']);
        }

        $payloadHash = $this->operationPayloadHash('return', $validated);

        DB::transaction(function () use ($order, $team, $request, $validated, $recordStockMovement, $payloadHash): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($this->isIdempotentReplay($lockedOrder, $validated['idempotency_key'], $payloadHash)) {
                return;
            }

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

            $warehouse = $team->warehouses()->whereKey($validated['warehouse_id'])->firstOrFail();
            $location = $warehouse->locations()->whereKey($validated['warehouse_location_id'])->firstOrFail();
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
                'idempotency_key' => $validated['idempotency_key'],
                'payload_hash' => $payloadHash,
            ]);
        });

        return back();
    }

    public function cancel(Request $request, string $currentTeamSlug, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->teamOrder($request, $purchaseOrder);
        Gate::authorize('cancel', $order);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $reason = trim($validated['reason']);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
        }

        DB::transaction(function () use ($order, $request, $reason): void {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('cancel', $lockedOrder);
            if (in_array($lockedOrder->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'This purchase order can no longer be cancelled.']);
            }

            $lockedOrder->update(['cancellation_reason' => $reason]);
            $this->transition($lockedOrder, PurchaseOrderStatus::Cancelled, $request->user()->id, $reason);
        });

        return back();
    }

    private function teamOrder(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $request->user()->currentTeam->purchaseOrders()->findOrFail($purchaseOrder->id);
    }

    /**
     * Validate fields shared by draft creation and revision.
     *
     * @return array{supplier_id: int, expected_date: string, reference?: string|null, items: array<int, array{inventory_item_id: int, quantity: int, unit_cost: string|int|float}>}
     */
    private function validatedDraft(Request $request, int $teamId): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $teamId)->whereNull('deleted_at')->where('is_active', true)],
            'expected_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->where('team_id', $teamId)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ]);
    }

    /**
     * Resolve team-owned items and calculate the order total using cents.
     *
     * @param  array{supplier_id: int, expected_date: string, reference?: string|null, items: array<int, array{inventory_item_id: int, quantity: int, unit_cost: string|int|float}>}  $validated
     * @return array{Collection<int, InventoryItem>, int}
     */
    private function draftSnapshot(int $teamId, array $validated): array
    {
        $items = Team::query()->findOrFail($teamId)->inventoryItems()
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

        return [$items, $totalCents];
    }

    /**
     * Persist immutable item name, SKU, and cost snapshots for a draft.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @param  array<int, array{inventory_item_id: int, quantity: int, unit_cost: string|int|float}>  $lines
     */
    private function createItemSnapshots(PurchaseOrder $order, Collection $items, array $lines): void
    {
        foreach ($lines as $line) {
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
     * Hash the normalized fields that define a receipt or supplier return.
     *
     * @param  array{purchase_order_item_id: int|string, warehouse_id: int|string, warehouse_location_id: int|string, quantity: int|string, reason?: string}  $validated
     */
    private function operationPayloadHash(string $operation, array $validated): string
    {
        $payload = [
            'operation' => $operation,
            'purchase_order_item_id' => (int) $validated['purchase_order_item_id'],
            'warehouse_id' => (int) $validated['warehouse_id'],
            'warehouse_location_id' => (int) $validated['warehouse_location_id'],
            'quantity' => (int) $validated['quantity'],
            'reason' => $operation === 'return' ? trim($validated['reason'] ?? '') : null,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function isIdempotentReplay(PurchaseOrder $order, string $key, string $payloadHash): bool
    {
        $event = $order->events()->where('idempotency_key', $key)->first();

        if ($event === null) {
            return false;
        }

        if (! hash_equals((string) $event->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This key was already used for a different purchasing operation or payload.',
            ]);
        }

        return true;
    }

    /**
     * Convert a purchase order and its history to the Inertia response shape.
     *
     * @param  array<int, int|string|null>  $returnedQuantities
     * @return array<string, mixed>
     */
    private function orderToArray(PurchaseOrder $order, array $returnedQuantities, Request $request): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number(),
            'supplierName' => $order->supplier->name,
            'supplierId' => $order->supplier_id,
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'expectedDate' => $order->expected_date->toDateString(),
            'reference' => $order->reference,
            'totalAmount' => (float) $order->total_amount,
            'createdBy' => $order->created_by === null ? 'Former user' : $order->creator->name,
            'createdById' => $order->created_by,
            'approvedBy' => $order->approver?->name,
            'cancellationReason' => $order->cancellation_reason,
            'canUpdate' => $request->user()->can('update', $order),
            'canSubmit' => $request->user()->can('submit', $order),
            'canApprove' => $request->user()->can('approve', $order),
            'canRequestRevision' => $request->user()->can('requestRevision', $order),
            'canCancel' => $request->user()->can('cancel', $order),
            'canReceive' => $request->user()->can('receive', $order),
            'canReturn' => $request->user()->can('returnGoods', $order),
            'items' => $order->items->map(fn (PurchaseOrderItem $item): array => [
                'id' => $item->id,
                'inventoryItemId' => $item->inventory_item_id,
                'name' => $item->item_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'receivedQuantity' => $item->received_quantity,
                'returnedQuantity' => (int) ($returnedQuantities[$item->id] ?? 0),
                'unitCost' => (float) $item->unit_cost,
                'lineTotal' => (float) $item->line_total,
            ])->values(),
            'events' => $order->events->map(fn ($event): array => [
                'id' => $event->id,
                'fromStatus' => $event->from_status,
                'toStatus' => $event->to_status,
                'note' => $event->note,
                'userName' => $event->user_id === null ? 'Former user' : $event->user->name,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
