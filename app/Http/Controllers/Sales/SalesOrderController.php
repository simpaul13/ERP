<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\ManageSalesOrder;
use App\Actions\Sales\SalesMoney;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\SalesFulfillment;
use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use App\Models\SalesOrderItem;
use App\Models\SalesRefund;
use App\Models\SalesReturn;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalesOrderController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SalesOrder::class);
        $team = $request->user()->currentTeam;

        return Inertia::render('sales-orders/Index', [
            'orders' => $team->salesOrders()->latest('id')->limit(100)->get()->map(fn (SalesOrder $order): array => $this->summary($order))->values(),
            'canCreate' => $request->user()->can('create', SalesOrder::class),
            'items' => $team->inventoryItems()->orderBy('name')->get(['id', 'name', 'sku'])->map(fn (InventoryItem $item): array => ['id' => $item->id, 'name' => $item->name, 'sku' => $item->sku])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', SalesOrder::class);
        $team = $request->user()->currentTeam;
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:2000'],
            'currency' => ['sometimes', 'required', Rule::in(['PHP'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->where('team_id', $team->id)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'regex:/^[0-9]{1,6}(?:\.[0-9]{1,2})?$/D'],
        ]);

        $order = DB::transaction(function () use ($data, $team, $request): SalesOrder {
            $items = $team->inventoryItems()->whereIn('id', array_column($data['items'], 'inventory_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = 0;
            foreach ($data['items'] as $line) {
                $total += SalesMoney::minor((string) $line['unit_price']) * (int) $line['quantity'];
            }
            if ($total > 99999999999999) {
                throw ValidationException::withMessages(['items' => 'The order total exceeds the supported limit.']);
            }
            $order = $team->salesOrders()->create([
                'created_by' => $request->user()->id,
                'customer_name' => trim($data['customer_name']),
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'currency' => 'PHP',
                'status' => SalesOrderStatus::Draft,
                'total_amount' => SalesMoney::decimal($total),
            ]);
            foreach ($data['items'] as $line) {
                $item = $items->get((int) $line['inventory_item_id']);
                if (! $item instanceof InventoryItem) {
                    throw ValidationException::withMessages(['items' => 'An inventory item is no longer available.']);
                }
                $price = SalesMoney::minor((string) $line['unit_price']);
                $order->items()->create([
                    'inventory_item_id' => $item->id,
                    'item_name' => $item->name,
                    'sku' => $item->sku,
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => SalesMoney::decimal($price),
                    'line_total' => SalesMoney::decimal($price * (int) $line['quantity']),
                ]);
            }
            $order->events()->create(['user_id' => $request->user()->id, 'to_status' => SalesOrderStatus::Draft->value, 'note' => 'Sales order created.']);

            return $order;
        });

        return to_route('sales-orders.show', ['current_team' => $team->slug, 'salesOrder' => $order->id]);
    }

    public function show(Request $request, string $currentTeamSlug, SalesOrder $salesOrder): Response
    {
        $order = $this->teamOrder($request, $salesOrder);
        Gate::authorize('view', $order);
        $order->load(['items', 'reservations.salesOrderItem', 'fulfillments.salesOrderItem', 'fulfillments.returns', 'returns.salesOrderItem', 'refunds', 'events.user']);
        $admin = $request->user()->can('manage', $order);
        $open = ! in_array($order->status, [SalesOrderStatus::Fulfilled, SalesOrderStatus::Cancelled], true);
        $hasFulfilled = $order->items->sum('fulfilled_quantity') > 0;

        return Inertia::render('sales-orders/Show', [
            'order' => [
                ...$this->summary($order),
                'customerEmail' => $order->customer_email,
                'customerPhone' => $order->customer_phone,
                'customerAddress' => $order->customer_address,
                'cancellationReason' => $order->cancellation_reason,
                'items' => $order->items->map(fn (SalesOrderItem $line): array => [
                    'id' => $line->id,
                    'inventoryItemId' => $line->inventory_item_id,
                    'name' => $line->item_name,
                    'sku' => $line->sku,
                    'quantity' => $line->quantity,
                    'fulfilledQuantity' => $line->fulfilled_quantity,
                    'cancelledQuantity' => $line->cancelled_quantity,
                    'reservedQuantity' => (int) $order->reservations->where('sales_order_item_id', $line->id)->sum('remaining_quantity'),
                    'returnedQuantity' => (int) $order->returns->where('sales_order_item_id', $line->id)->sum('quantity'),
                    'unitPrice' => $line->unit_price,
                    'lineTotal' => $line->line_total,
                ])->values(),
                'reservations' => $order->reservations->map(fn (StockReservation $reservation): array => [
                    'id' => $reservation->id,
                    'salesOrderItemId' => $reservation->sales_order_item_id,
                    'itemName' => $reservation->salesOrderItem->item_name,
                    'warehouseName' => $reservation->warehouse_name,
                    'locationName' => $reservation->location_name,
                    'quantity' => $reservation->quantity,
                    'remainingQuantity' => $reservation->remaining_quantity,
                ])->values(),
                'fulfillments' => $order->fulfillments->map(fn (SalesFulfillment $fulfillment): array => [
                    'id' => $fulfillment->id,
                    'salesOrderItemId' => $fulfillment->sales_order_item_id,
                    'itemName' => $fulfillment->salesOrderItem->item_name,
                    'quantity' => $fulfillment->quantity,
                    'returnedQuantity' => (int) $fulfillment->returns->sum('quantity'),
                    'returnableQuantity' => $fulfillment->quantity - (int) $fulfillment->returns->sum('quantity'),
                    'warehouseName' => $fulfillment->warehouse_name,
                    'locationName' => $fulfillment->location_name,
                ])->values(),
                'returns' => $order->returns->map(fn (SalesReturn $return): array => [
                    'id' => $return->id,
                    'itemName' => $return->salesOrderItem->item_name,
                    'quantity' => $return->quantity,
                    'condition' => $return->condition,
                    'accepted' => $return->accepted,
                    'reason' => $return->reason,
                    'refundableAmount' => $return->refundable_amount,
                    'remainingRefundableAmount' => SalesMoney::decimal($return->remainingRefundableMinor()),
                ])->values(),
                'refunds' => $order->refunds->map(fn (SalesRefund $refund): array => ['id' => $refund->id, 'salesReturnId' => $refund->sales_return_id, 'amount' => $refund->amount, 'status' => $refund->status, 'reference' => $refund->reference])->values(),
                'events' => $order->events->map(fn (SalesOrderEvent $event): array => ['id' => $event->id, 'note' => $event->note, 'userName' => $event->user->name ?? 'Former user', 'createdAt' => $event->created_at?->toIso8601String()])->values(),
            ],
            'canReserve' => $admin && $open,
            'canFulfill' => $admin && $open && $order->reservations->sum('remaining_quantity') > 0,
            'canCancel' => $open && $request->user()->can('cancel', $order),
            'canReturn' => $admin && $hasFulfilled,
            'canRefund' => $admin && $order->returns->contains('accepted', true),
            'warehouses' => $order->team->warehouses()->with('locations')->orderBy('name')->get()->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id, 'name' => $warehouse->name,
                'locations' => $warehouse->locations->map(fn ($location): array => ['id' => $location->id, 'name' => $location->name])->values(),
            ])->values(),
        ]);
    }

    public function reserve(Request $request, string $currentTeamSlug, SalesOrder $salesOrder, ManageSalesOrder $action): RedirectResponse
    {
        $order = $this->teamOrder($request, $salesOrder);
        Gate::authorize('reserve', $order);
        $data = $request->validate([
            'sales_order_item_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'warehouse_location_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $action->reserve($order, $request->user(), [
            'sales_order_item_id' => (int) $data['sales_order_item_id'],
            'warehouse_id' => (int) $data['warehouse_id'],
            'warehouse_location_id' => (int) $data['warehouse_location_id'],
            'quantity' => (int) $data['quantity'],
        ]);

        return back();
    }

    public function fulfill(Request $request, string $currentTeamSlug, SalesOrder $salesOrder, ManageSalesOrder $action): RedirectResponse
    {
        $order = $this->teamOrder($request, $salesOrder);
        Gate::authorize('fulfill', $order);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reservation_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $action->fulfill($order, $request->user(), ['idempotency_key' => strtolower($data['idempotency_key']), 'reservation_id' => (int) $data['reservation_id'], 'quantity' => (int) $data['quantity']]);

        return back();
    }

    public function cancel(Request $request, string $currentTeamSlug, SalesOrder $salesOrder, ManageSalesOrder $action): RedirectResponse
    {
        $order = $this->teamOrder($request, $salesOrder);
        Gate::authorize('cancel', $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->cancel($order, $request->user(), trim($data['reason']));

        return back();
    }

    public function returnGoods(Request $request, string $currentTeamSlug, SalesOrder $salesOrder, ManageSalesOrder $action): RedirectResponse
    {
        $order = $this->teamOrder($request, $salesOrder);
        Gate::authorize('returnGoods', $order);
        $restock = $request->boolean('accepted') && $request->input('condition') === 'good';
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'fulfillment_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'condition' => ['required', Rule::in(['good', 'damaged', 'unsellable'])],
            'accepted' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
            'warehouse_id' => [Rule::requiredIf($restock), 'nullable', 'integer', 'min:1'],
            'warehouse_location_id' => [Rule::requiredIf($restock), 'nullable', 'integer', 'min:1'],
        ]);
        $action->returnGoods($order, $request->user(), [
            'idempotency_key' => strtolower($data['idempotency_key']),
            'fulfillment_id' => (int) $data['fulfillment_id'],
            'quantity' => (int) $data['quantity'],
            'condition' => $data['condition'],
            'accepted' => (bool) $data['accepted'],
            'reason' => trim($data['reason']),
            'warehouse_id' => $restock ? (int) $data['warehouse_id'] : null,
            'warehouse_location_id' => $restock ? (int) $data['warehouse_location_id'] : null,
        ]);

        return back();
    }

    public function refund(Request $request, string $currentTeamSlug, SalesOrder $salesOrder, ManageSalesOrder $action): RedirectResponse
    {
        $order = $this->teamOrder($request, $salesOrder);
        Gate::authorize('refund', $order);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'sales_return_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/D'],
            'status' => ['required', Rule::in(['pending', 'refunded'])],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $action->refund($order, $request->user(), [
            'idempotency_key' => strtolower($data['idempotency_key']),
            'sales_return_id' => (int) $data['sales_return_id'],
            'amount' => SalesMoney::decimal(SalesMoney::minor((string) $data['amount'])),
            'status' => $data['status'],
            'reference' => isset($data['reference']) ? trim($data['reference']) : null,
        ]);

        return back();
    }

    public function updateRefund(Request $request, string $currentTeamSlug, SalesOrder $salesOrder, SalesRefund $salesRefund, ManageSalesOrder $action): RedirectResponse
    {
        $order = $this->teamOrder($request, $salesOrder);
        Gate::authorize('refund', $order);
        $data = $request->validate([
            'status' => ['required', Rule::in(['refunded', 'cancelled'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'amount' => ['prohibited'],
        ]);
        $update = ['status' => $data['status']];
        if (array_key_exists('reference', $data)) {
            $update['reference'] = $data['reference'] !== null ? trim($data['reference']) : null;
        }
        $action->updateRefund($order, $request->user(), $salesRefund->id, $update);

        return back();
    }

    private function teamOrder(Request $request, SalesOrder $order): SalesOrder
    {
        return $request->user()->currentTeam->salesOrders()->findOrFail($order->id);
    }

    /** @return array{id: int, number: string, customerName: string, status: string, statusLabel: string, currency: string, totalAmount: string, createdAt: string|null} */
    private function summary(SalesOrder $order): array
    {
        return [
            'id' => $order->id, 'number' => $order->number(), 'customerName' => $order->customer_name,
            'status' => $order->status->value, 'statusLabel' => $order->status->label(),
            'currency' => $order->currency, 'totalAmount' => $order->total_amount,
            'createdAt' => $order->created_at?->toIso8601String(),
        ];
    }
}
