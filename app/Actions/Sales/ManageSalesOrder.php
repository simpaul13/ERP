<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageSalesOrder
{
    public function __construct(private RecordStockMovement $recordStockMovement) {}

    /** @param array{idempotency_key: string, sales_order_item_id: int, warehouse_id: int, warehouse_location_id: int, quantity: int} $data */
    public function reserve(SalesOrder $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data): void {
            $order = $this->locked($order, $user, 'reserve');
            $hash = $this->hash($data);
            if ($this->replayed($order->reservations()->where('idempotency_key', $data['idempotency_key'])->first(), $hash)) {
                return;
            }

            $this->requireOpen($order);
            $line = $order->items()->whereKey($data['sales_order_item_id'])->firstOrFail();
            $item = $this->inventory($line);
            $reserved = (int) $line->reservations()->lockForUpdate()->get()->sum('remaining_quantity');
            $remaining = $line->quantity - $line->fulfilled_quantity - $line->cancelled_quantity - $reserved;
            $quantity = $data['quantity'];

            if ($quantity > $remaining) {
                $this->invalid('quantity', "Only {$remaining} unit(s) remain available to reserve on this line.");
            }

            $warehouse = $order->team->warehouses()->whereKey($data['warehouse_id'])->lockForUpdate()->firstOrFail();
            $location = $warehouse->locations()->whereKey($data['warehouse_location_id'])->lockForUpdate()->firstOrFail();
            $physical = (int) StockLevel::query()->where('inventory_item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)->where('warehouse_location_id', $location->id)->lockForUpdate()->value('quantity');
            $held = (int) StockReservation::query()->where('inventory_item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)->where('warehouse_location_id', $location->id)->lockForUpdate()->get()->sum('remaining_quantity');

            if ($quantity > $physical - $held) {
                $this->invalid('quantity', 'There is not enough unreserved stock in this bin.');
            }

            $order->reservations()->create([
                'sales_order_item_id' => $line->id,
                'inventory_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_location_id' => $location->id,
                'warehouse_name' => $warehouse->name,
                'location_name' => $location->name,
                'quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
            ]);
            $status = $order->status === SalesOrderStatus::Draft ? SalesOrderStatus::Reserved : $order->status;
            $this->event($order, $user, "Reserved {$quantity} × {$line->item_name} in {$warehouse->name} / {$location->name}.", $status);
        });
    }

    /** @param array{idempotency_key: string, reservation_id: int, quantity: int} $data */
    public function fulfill(SalesOrder $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data): void {
            $order = $this->locked($order, $user, 'fulfill');
            $hash = $this->hash($data);
            if ($this->replayed($order->fulfillments()->where('idempotency_key', $data['idempotency_key'])->first(), $hash)) {
                return;
            }
            $this->requireOpen($order);
            $reservation = $order->reservations()->findOrFail($data['reservation_id']);
            $line = $order->items()->findOrFail($reservation->sales_order_item_id);
            $item = $this->inventory($line);
            $quantity = $data['quantity'];

            if ($quantity > $reservation->remaining_quantity) {
                $this->invalid('quantity', 'The fulfillment exceeds the remaining reserved quantity.');
            }

            $warehouse = $order->team->warehouses()->findOrFail($reservation->warehouse_id);
            $location = $warehouse->locations()->findOrFail($reservation->warehouse_location_id);
            $reservation->decrement('remaining_quantity', $quantity);
            $movement = $this->recordStockMovement->handle(
                team: $order->team, inventoryItem: $item, warehouse: $warehouse, location: $location,
                type: StockMovementType::Issue, quantity: -$quantity, unitCost: $item->unit_cost,
                reference: $order->number(), reason: 'Sales fulfillment', user: $user,
            );
            $order->fulfillments()->create([
                'sales_order_item_id' => $line->id,
                'stock_reservation_id' => $reservation->id,
                'stock_movement_id' => $movement->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_location_id' => $location->id,
                'warehouse_name' => $warehouse->name,
                'location_name' => $location->name,
                'quantity' => $quantity,
                'unit_cost' => $movement->unit_cost,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
            ]);
            $line->increment('fulfilled_quantity', $quantity);
            $complete = ! $order->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->exists();
            $this->event($order, $user, "Fulfilled {$quantity} × {$line->item_name}.", $complete ? SalesOrderStatus::Fulfilled : SalesOrderStatus::PartiallyFulfilled);
        });
    }

    public function cancel(SalesOrder $order, User $user, string $reason): void
    {
        DB::transaction(function () use ($order, $user, $reason): void {
            $order = $this->locked($order, $user, 'cancel');
            if ($order->status === SalesOrderStatus::Cancelled) {
                return;
            }
            $this->requireOpen($order);
            $itemIds = $order->reservations()->where('remaining_quantity', '>', 0)->pluck('inventory_item_id')->filter()->unique()->sort()->values();
            InventoryItem::query()->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get();
            $order->reservations()->where('remaining_quantity', '>', 0)->update(['remaining_quantity' => 0]);
            foreach ($order->items()->get() as $line) {
                $line->update(['cancelled_quantity' => $line->quantity - $line->fulfilled_quantity]);
            }
            $order->update(['cancellation_reason' => $reason]);
            $this->event($order, $user, 'Order cancelled: '.$reason, SalesOrderStatus::Cancelled);
        });
    }

    /** @param array{idempotency_key: string, fulfillment_id: int, quantity: int, condition: string, accepted: bool, reason: string, warehouse_id: int|null, warehouse_location_id: int|null} $data */
    public function returnGoods(SalesOrder $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data): void {
            $order = $this->locked($order, $user, 'returnGoods');
            $hash = $this->hash($data);
            if ($this->replayed($order->returns()->where('idempotency_key', $data['idempotency_key'])->first(), $hash)) {
                return;
            }
            $fulfillment = $order->fulfillments()->findOrFail($data['fulfillment_id']);
            $line = $order->items()->findOrFail($fulfillment->sales_order_item_id);
            $returned = (int) $fulfillment->returns()->sum('quantity');
            $quantity = $data['quantity'];
            if ($quantity > $fulfillment->quantity - $returned) {
                $this->invalid('quantity', 'The return exceeds the delivered quantity not already returned.');
            }
            $movement = null;
            if ($data['accepted'] && $data['condition'] === 'good') {
                $item = $this->inventory($line);
                $warehouse = $order->team->warehouses()->findOrFail($data['warehouse_id']);
                $location = $warehouse->locations()->findOrFail($data['warehouse_location_id']);
                $movement = $this->recordStockMovement->handle(
                    team: $order->team, inventoryItem: $item, warehouse: $warehouse, location: $location,
                    type: StockMovementType::Receipt, quantity: $quantity, unitCost: $fulfillment->unit_cost,
                    reference: $order->number(), reason: 'Sales return: '.$data['reason'], user: $user,
                );
            }
            $order->returns()->create([
                'sales_order_item_id' => $line->id,
                'sales_fulfillment_id' => $fulfillment->id,
                'stock_movement_id' => $movement?->id,
                'quantity' => $quantity,
                'condition' => $data['condition'],
                'accepted' => $data['accepted'],
                'reason' => $data['reason'],
                'refundable_amount' => SalesMoney::decimal($data['accepted'] ? SalesMoney::minor($line->unit_price) * $quantity : 0),
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
            ]);
            $decision = $data['accepted'] ? 'accepted' : 'rejected';
            $this->event($order, $user, "Return {$decision}: {$quantity} × {$line->item_name} ({$data['condition']}): {$data['reason']}");
        });
    }

    /** @param array{idempotency_key: string, sales_return_id: int, amount: string, status: string, reference: string|null} $data */
    public function refund(SalesOrder $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data): void {
            $order = $this->locked($order, $user, 'refund');
            $hash = $this->hash($data);
            if ($this->replayed($order->refunds()->where('idempotency_key', $data['idempotency_key'])->first(), $hash)) {
                return;
            }
            $return = $order->returns()->findOrFail($data['sales_return_id']);
            $minor = SalesMoney::minor($data['amount']);
            if (! $return->accepted || $minor < 1 || $minor > $return->remainingRefundableMinor()) {
                $this->invalid('amount', 'The refund must be positive and within this accepted return’s remaining refundable amount.');
            }
            $refund = $order->refunds()->create([
                'sales_return_id' => $return->id,
                'amount' => $data['amount'],
                'status' => $data['status'],
                'reference' => $data['reference'],
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
            ]);
            $this->event($order, $user, "Refund #{$refund->id} tracked: {$order->currency} {$refund->amount} ({$refund->status}); reference: ".($refund->reference ?? 'none').'.');
        });
    }

    /** @param array{status: string, reference?: string|null} $data */
    public function updateRefund(SalesOrder $order, User $user, int $refundId, array $data): void
    {
        DB::transaction(function () use ($order, $user, $refundId, $data): void {
            $order = $this->locked($order, $user, 'refund');
            $refund = $order->refunds()->findOrFail($refundId);
            if ($refund->status !== 'pending') {
                $this->invalid('status', 'Only pending refunds can be changed.');
            }
            $oldReference = $refund->reference;
            $refund->update($data);
            $this->event($order, $user, "Refund #{$refund->id}: pending → {$refund->status}; reference: ".($oldReference ?? 'none').' → '.($refund->reference ?? 'none').'.');
        });
    }

    private function locked(SalesOrder $order, User $user, string $ability): SalesOrder
    {
        $order = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($user)->authorize($ability, $order);

        return $order;
    }

    private function inventory(SalesOrderItem $line): InventoryItem
    {
        $item = InventoryItem::query()->whereKey($line->inventory_item_id)->lockForUpdate()->first();
        if ($item === null) {
            $this->invalid('sales_order_item_id', 'The original inventory item is no longer available.');
        }

        return $item;
    }

    private function requireOpen(SalesOrder $order): void
    {
        if (in_array($order->status, [SalesOrderStatus::Fulfilled, SalesOrderStatus::Cancelled], true)) {
            $this->invalid('sales_order', 'This sales order has no open quantity.');
        }
    }

    /** @param array<string, mixed> $data */
    private function hash(array $data): string
    {
        unset($data['idempotency_key']);
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function replayed(?Model $record, string $hash): bool
    {
        if ($record === null) {
            return false;
        }
        if (! hash_equals((string) $record->getAttribute('payload_hash'), $hash)) {
            $this->invalid('idempotency_key', 'This idempotency key was already used with a different payload.');
        }

        return true;
    }

    private function event(SalesOrder $order, User $user, string $note, ?SalesOrderStatus $status = null): void
    {
        $previous = $order->status;
        $status ??= $previous;
        if ($status !== $previous) {
            $order->update(['status' => $status]);
        }
        $order->events()->create(['user_id' => $user->id, 'from_status' => $previous->value, 'to_status' => $status->value, 'note' => $note]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
