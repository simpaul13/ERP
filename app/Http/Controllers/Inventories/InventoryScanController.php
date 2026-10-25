<?php

namespace App\Http\Controllers\Inventories;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryScanEvent;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryScanController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('viewAny', InventoryItem::class);

        return Inertia::render('inventories/Scan', [
            'warehouses' => $team->warehouses()
                ->with(['locations' => fn ($query) => $query->orderByDesc('is_default')->orderBy('name')])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (Warehouse $warehouse): array => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'locations' => $warehouse->locations->map(fn (WarehouseLocation $location): array => [
                        'id' => $location->id,
                        'name' => $location->name,
                        'code' => $location->code,
                    ])->values(),
                ])->values(),
            'canScan' => $request->user()->can('create', InventoryItem::class),
        ]);
    }

    public function store(Request $request, RecordStockMovement $recordStockMovement): JsonResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('create', InventoryItem::class);

        $validated = $request->validate([
            'workflow' => ['required', 'string', 'in:receiving,picking,cycle_count'],
            'transaction_id' => ['required', 'uuid'],
            'scan_id' => ['required', 'uuid'],
            'item_code' => ['required', 'string', 'max:100'],
            'bin_code' => ['required', 'string', 'max:50'],
            'warehouse_id' => ['required', 'integer'],
            'quantity' => [
                'required',
                'integer',
                $request->input('workflow') === 'cycle_count' ? 'min:0' : 'min:1',
                'max:1000000',
            ],
        ]);

        $existingScan = InventoryScanEvent::query()
            ->where('team_id', $team->id)
            ->where('scan_id', $validated['scan_id'])
            ->first();

        if ($existingScan instanceof InventoryScanEvent) {
            abort_unless($existingScan->user_id === $request->user()->id, 409, 'This scan ID belongs to another user.');
            abort_unless(
                $existingScan->transaction_id === $validated['transaction_id']
                    && $existingScan->workflow === $validated['workflow']
                    && $existingScan->item_code === strtoupper(trim($validated['item_code']))
                    && $existingScan->bin_code === strtoupper(trim($validated['bin_code']))
                    && $existingScan->warehouse_id === (int) $validated['warehouse_id']
                    && $existingScan->quantity === (int) $validated['quantity'],
                409,
                'This scan ID was already used for a different scan.',
            );

            return response()->json($this->eventResponse($existingScan, duplicate: true));
        }

        $itemCode = strtoupper(trim($validated['item_code']));
        $binCode = strtoupper(trim($validated['bin_code']));
        $item = $team->inventoryItems()->whereRaw('UPPER(sku) = ?', [$itemCode])->first();

        if (! $item instanceof InventoryItem) {
            throw ValidationException::withMessages(['item_code' => "Unknown item barcode or SKU: {$itemCode}."]);
        }

        $warehouse = $team->warehouses()->find($validated['warehouse_id']);
        if (! $warehouse instanceof Warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose a warehouse in your team.']);
        }

        $location = $warehouse->locations()->whereRaw('UPPER(code) = ?', [$binCode])->first();
        if (! $location instanceof WarehouseLocation) {
            throw ValidationException::withMessages(['bin_code' => "Bin barcode {$binCode} was not found in {$warehouse->name}."]);
        }

        return DB::transaction(function () use ($request, $team, $validated, $recordStockMovement, $item, $warehouse, $location, $itemCode, $binCode): JsonResponse {
            $item = $team->inventoryItems()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $existingScan = InventoryScanEvent::query()
                ->where('team_id', $team->id)
                ->where('scan_id', $validated['scan_id'])
                ->lockForUpdate()
                ->first();

            if ($existingScan instanceof InventoryScanEvent) {
                abort_unless($existingScan->user_id === $request->user()->id, 409, 'This scan ID belongs to another user.');
                abort_unless(
                    $existingScan->transaction_id === $validated['transaction_id']
                        && $existingScan->workflow === $validated['workflow']
                        && $existingScan->item_code === $itemCode
                        && $existingScan->bin_code === $binCode
                        && $existingScan->warehouse_id === (int) $validated['warehouse_id']
                        && $existingScan->quantity === (int) $validated['quantity'],
                    409,
                    'This scan ID was already used for a different scan.',
                );

                return response()->json($this->eventResponse($existingScan, duplicate: true));
            }

            $countedQuantity = (int) $validated['quantity'];
            $movement = null;

            if ($validated['workflow'] === 'receiving') {
                $movement = $recordStockMovement->handle(
                    team: $team,
                    inventoryItem: $item,
                    warehouse: $warehouse,
                    location: $location,
                    type: StockMovementType::Receipt,
                    quantity: $countedQuantity,
                    unitCost: (float) $item->unit_cost,
                    reference: 'SCAN:'.$validated['transaction_id'],
                    reason: "Barcode receiving transaction {$validated['transaction_id']}",
                    user: $request->user(),
                );
            } elseif ($validated['workflow'] === 'picking') {
                $movement = $recordStockMovement->handle(
                    team: $team,
                    inventoryItem: $item,
                    warehouse: $warehouse,
                    location: $location,
                    type: StockMovementType::Issue,
                    quantity: -$countedQuantity,
                    unitCost: (float) $item->unit_cost,
                    reference: 'SCAN:'.$validated['transaction_id'],
                    reason: "Barcode picking transaction {$validated['transaction_id']}",
                    user: $request->user(),
                );
            } else {
                $currentQuantity = (int) StockLevel::query()
                    ->where('inventory_item_id', $item->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('warehouse_location_id', $location->id)
                    ->value('quantity');
                $difference = $countedQuantity - $currentQuantity;

                if ($difference !== 0) {
                    $movement = $recordStockMovement->handle(
                        team: $team,
                        inventoryItem: $item,
                        warehouse: $warehouse,
                        location: $location,
                        type: StockMovementType::Adjustment,
                        quantity: $difference,
                        unitCost: (float) $item->unit_cost,
                        reference: 'SCAN:'.$validated['transaction_id'],
                        reason: "Barcode cycle count: {$currentQuantity} on hand, {$countedQuantity} counted",
                        user: $request->user(),
                    );
                }
            }

            $event = InventoryScanEvent::query()->create([
                'team_id' => $team->id,
                'user_id' => $request->user()->id,
                'transaction_id' => $validated['transaction_id'],
                'scan_id' => $validated['scan_id'],
                'workflow' => $validated['workflow'],
                'inventory_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_location_id' => $location->id,
                'item_code' => $itemCode,
                'bin_code' => $binCode,
                'quantity' => $countedQuantity,
                'stock_movement_id' => $movement?->id,
            ]);

            return response()->json($this->eventResponse($event));
        });
    }

    /** @return array<string, mixed> */
    private function eventResponse(InventoryScanEvent $event, bool $duplicate = false): array
    {
        $movement = $event->stock_movement_id
            ? StockMovement::query()->find($event->stock_movement_id)
            : null;

        return [
            'scanId' => $event->scan_id,
            'workflow' => $event->workflow,
            'itemCode' => $event->item_code,
            'binCode' => $event->bin_code,
            'quantity' => $event->quantity,
            'movementId' => $event->stock_movement_id,
            'quantityAfter' => $movement?->quantity_after,
            'duplicate' => $duplicate,
            'message' => $duplicate ? 'Duplicate scan ignored; the original action was kept.' : 'Scan recorded successfully.',
        ];
    }
}
