<?php

namespace App\Http\Controllers\Inventories;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class InventoryController extends Controller
{
    /**
     * Display inventory items for the current team.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewAny', InventoryItem::class);

        $status = $request->input('status');
        $warehouse = (int) $request->input('warehouse', 0);
        $warehouse = $warehouse > 0 && $team->warehouses()->whereKey($warehouse)->exists()
            ? $warehouse
            : 0;
        $filters = [
            'search' => (string) $request->string('search')->trim(),
            'status' => in_array($status, ['low', 'out'], true) ? $status : 'all',
            'warehouse' => $warehouse,
        ];
        $requestedPerPage = (int) $request->input('per_page', 15);
        $perPage = in_array($requestedPerPage, [10, 15, 25], true) ? $requestedPerPage : 15;

        $inventoryItems = $team->inventoryItems()
            ->with(['stockLevels.warehouse', 'stockLevels.location'])
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = '%'.$filters['search'].'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', $search)
                        ->orWhere('sku', 'like', $search)
                        ->orWhere('category', 'like', $search)
                        ->orWhereHas('stockLevels.warehouse', fn ($query) => $query
                            ->where('name', 'like', $search)
                            ->orWhere('code', 'like', $search))
                        ->orWhereHas('stockLevels.location', fn ($query) => $query
                            ->where('name', 'like', $search)
                            ->orWhere('code', 'like', $search));
                });
            })
            ->when($filters['warehouse'] > 0, fn ($query) => $query
                ->whereHas('stockLevels', fn ($query) => $query
                    ->where('warehouse_id', $filters['warehouse'])))
            ->when($filters['status'] === 'low', fn ($query) => $query
                ->where('quantity', '>', 0)
                ->whereColumn('quantity', '<=', 'reorder_point'))
            ->when($filters['status'] === 'out', fn ($query) => $query
                ->where('quantity', 0))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (InventoryItem $item): array => $this->toArray($item));

        $warehouses = $this->warehouseOptions($team);

        $activities = Activity::query()
            ->with('causer')
            ->where('log_name', 'inventory')
            ->where('properties->team_id', $team->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(function (Activity $activity): array {
                $itemName = $activity->getProperty('item_name');
                $sku = $activity->getProperty('sku');
                $causer = $activity->causer;

                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'itemName' => is_string($itemName) ? $itemName : null,
                    'sku' => is_string($sku) ? $sku : null,
                    'userName' => $causer instanceof User ? $causer->name : 'System',
                    'createdAt' => $activity->created_at?->toIso8601String(),
                ];
            });

        return Inertia::render('inventories/Index', [
            'inventoryItems' => $inventoryItems,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $team->defaultWarehouse()?->id,
            'filters' => $filters,
            'summary' => [
                'totalItems' => $team->inventoryItems()->count(),
                'totalUnits' => (int) $team->inventoryItems()->sum('quantity'),
                'lowStockCount' => $team->inventoryItems()
                    ->where('quantity', '>', 0)
                    ->whereColumn('quantity', '<=', 'reorder_point')
                    ->count(),
                'outOfStockCount' => $team->inventoryItems()
                    ->where('quantity', 0)
                    ->count(),
                'inventoryValue' => (float) $team->inventoryItems()
                    ->sum(DB::raw('quantity * unit_cost')),
            ],
            'activities' => $activities,
            'canManage' => $request->user()->can('create', InventoryItem::class),
        ]);
    }

    /**
     * Display the profile page for a single inventory item.
     */
    public function show(Request $request, string $currentTeamSlug, InventoryItem $inventoryItem): Response
    {
        $team = $request->user()->currentTeam;

        $item = $team->inventoryItems()
            ->with(['stockLevels.warehouse', 'stockLevels.location'])
            ->findOrFail($inventoryItem->id);

        Gate::authorize('view', $item);

        $movements = $item->stockMovements()
            ->with(['warehouse', 'location', 'user'])
            ->latest()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (StockMovement $movement): array => $this->movementToArray($movement))
            ->values();

        return Inertia::render('inventories/Show', [
            'item' => $this->toArray($item),
            'movements' => $movements,
            'warehouses' => $this->warehouseOptions($team),
            'defaultWarehouseId' => $team->defaultWarehouse()?->id,
            'movementCount' => $item->stockMovements()->count(),
            'canManage' => $request->user()->can('update', $item),
        ]);
    }

    /**
     * Store a new inventory item for the current team.
     */
    public function store(Request $request, RecordStockMovement $recordStockMovement): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('create', InventoryItem::class);

        $request->merge([
            'sku' => strtoupper(trim((string) $request->input('sku'))),
            'name' => trim((string) $request->input('name')),
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('inventory_items', 'sku')->where('team_id', $team->id),
            ],
            'category' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('team_id', $team->id),
            ],
            'warehouse_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->where('warehouse_id', (int) $request->input('warehouse_id')),
            ],
            'quantity' => ['required', 'integer', 'min:0'],
            'reorder_point' => ['required', 'integer', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['file', 'image', 'max:10240'],
            'image_ids' => ['sometimes', 'array', 'max:10'],
            'image_ids.*' => ['required', 'uuid'],
            'main_image_id' => ['nullable', 'uuid'],
        ]);

        $imageFiles = $request->file('images', []);
        $imageIds = $validated['image_ids'] ?? [];
        abort_unless(count($imageFiles) === count($imageIds), 422);
        abort_if(
            isset($validated['main_image_id']) && ! in_array($validated['main_image_id'], $imageIds, true),
            422,
        );
        $storedPaths = [];

        try {
            DB::transaction(function () use ($team, $validated, $imageFiles, $imageIds, $request, $recordStockMovement, &$storedPaths): void {
                $images = [];

                foreach ($imageFiles as $index => $imageFile) {
                    $path = $imageFile->store('inventory/'.$team->id, 'public');
                    abort_unless($path !== false, 500);
                    $storedPaths[] = $path;
                    $images[] = [
                        'id' => $imageIds[$index],
                        'name' => $imageFile->getClientOriginalName(),
                        'src' => Storage::disk('public')->url($path),
                        'path' => $path,
                    ];
                }

                $item = $team->inventoryItems()->create([
                    'sku' => strtoupper(trim($validated['sku'])),
                    'name' => trim($validated['name']),
                    'category' => $validated['category'] ?? null,
                    'quantity' => 0,
                    'reorder_point' => $validated['reorder_point'],
                    'unit_cost' => $validated['unit_cost'],
                    'images' => $images,
                    'main_image_id' => $validated['main_image_id'] ?? null,
                ]);

                if ((int) $validated['quantity'] > 0) {
                    $warehouse = $team->warehouses()->findOrFail($validated['warehouse_id']);
                    $location = isset($validated['warehouse_location_id'])
                        ? $warehouse->locations()->findOrFail($validated['warehouse_location_id'])
                        : $warehouse->defaultLocation();

                    $recordStockMovement->handle(
                        team: $team,
                        inventoryItem: $item,
                        warehouse: $warehouse,
                        location: $location,
                        type: StockMovementType::Opening,
                        quantity: (int) $validated['quantity'],
                        unitCost: (float) $validated['unit_cost'],
                        reason: 'Opening balance',
                        user: $request->user(),
                    );
                }

                activity('inventory')
                    ->causedBy($request->user())
                    ->withProperties([
                        'team_id' => $team->id,
                        'item_name' => $item->name,
                        'sku' => $item->sku,
                        'image_names' => array_column($images, 'name'),
                    ])
                    ->log('Inventory Created');

            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);

            throw $exception;
        }

        return back();
    }

    /**
     * Update an existing inventory item.
     */
    public function update(Request $request, string $currentTeamSlug, InventoryItem $inventoryItem): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $item = $team->inventoryItems()->findOrFail($inventoryItem->id);

        Gate::authorize('update', $item);

        $request->merge([
            'sku' => strtoupper(trim((string) $request->input('sku'))),
            'name' => trim((string) $request->input('name')),
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('inventory_items', 'sku')
                    ->where('team_id', $team->id)
                    ->ignore($item->id),
            ],
            'category' => ['nullable', 'string', 'max:255'],
            'reorder_point' => ['required', 'integer', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $oldValues = $item->only([
            'name',
            'sku',
            'category',
            'reorder_point',
            'unit_cost',
        ]);
        $item->update($validated);

        $changes = [];

        foreach ($oldValues as $field => $oldValue) {
            $newValue = $item->getAttribute($field);

            if ((string) $oldValue !== (string) $newValue) {
                $changes[$field] = [
                    'from' => $oldValue,
                    'to' => $newValue,
                ];
            }
        }

        activity('inventory')
            ->causedBy($request->user())
            ->withProperties([
                'team_id' => $team->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'changes' => $changes,
            ])
            ->log('Inventory Updated');

        return back();
    }

    /**
     * Delete an inventory item and its stored images.
     */
    public function destroy(Request $request, string $currentTeamSlug, InventoryItem $inventoryItem): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        DB::transaction(function () use ($team, $request, $inventoryItem): void {
            $item = $team->inventoryItems()->whereKey($inventoryItem->id)->lockForUpdate()->firstOrFail();

            Gate::authorize('delete', $item);

            $images = collect($item->images ?? []);
            $imageNames = $images->pluck('name')->all();
            $imagePaths = $images
                ->map(fn (array $image): ?string => $image['path'] ?? $this->storagePathFromUrl($image['src']))
                ->filter()
                ->values()
                ->all();

            if (! $item->delete()) {
                throw ValidationException::withMessages([
                    'inventory_item' => 'The inventory item could not be deleted.',
                ]);
            }

            activity('inventory')
                ->causedBy($request->user())
                ->withProperties([
                    'team_id' => $team->id,
                    'item_name' => $item->name,
                    'sku' => $item->sku,
                    'image_names' => $imageNames,
                ])
                ->log('Inventory Deleted');

            DB::afterCommit(static function () use ($imagePaths): void {
                Storage::disk('public')->delete($imagePaths);
            });
        });

        return back();
    }

    /**
     * Record a stock receipt or adjustment for an inventory item.
     */
    public function stock(
        Request $request,
        string $currentTeamSlug,
        InventoryItem $inventoryItem,
        RecordStockMovement $recordStockMovement,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        $item = $team->inventoryItems()->findOrFail($inventoryItem->id);

        Gate::authorize('adjust', $item);

        $validated = $request->validate([
            'direction' => ['required', Rule::in(['receive', 'remove'])],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('team_id', $team->id),
            ],
            'warehouse_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->where('warehouse_id', (int) $request->input('warehouse_id')),
            ],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => [
                $request->input('direction') === 'remove' ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $warehouse = $team->warehouses()->findOrFail($validated['warehouse_id']);
        $location = isset($validated['warehouse_location_id'])
            ? $warehouse->locations()->findOrFail($validated['warehouse_location_id'])
            : $warehouse->defaultLocation();
        $amount = (int) $validated['amount'];
        $isReceipt = $validated['direction'] === 'receive';

        $recordStockMovement->handle(
            team: $team,
            inventoryItem: $item,
            warehouse: $warehouse,
            location: $location,
            type: $isReceipt ? StockMovementType::Receipt : StockMovementType::Adjustment,
            quantity: $isReceipt ? $amount : -$amount,
            unitCost: (float) $item->unit_cost,
            reference: ($validated['reference'] ?? '') ?: null,
            reason: ($validated['reason'] ?? '') ?: ($isReceipt ? 'Stock received' : null),
            user: $request->user(),
        );

        return back();
    }

    /**
     * Return the movement history for an inventory item.
     */
    public function movements(Request $request, string $currentTeamSlug, InventoryItem $inventoryItem): JsonResponse
    {
        $team = $request->user()->currentTeam;
        $item = $team->inventoryItems()->findOrFail($inventoryItem->id);

        Gate::authorize('view', $item);

        $movements = $item->stockMovements()
            ->with(['warehouse', 'location', 'user'])
            ->latest()
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (StockMovement $movement): array => $this->movementToArray($movement));

        return response()->json($movements);
    }

    /**
     * Persist product image changes for an inventory item.
     */
    public function updateImages(Request $request, string $currentTeamSlug, InventoryItem $inventoryItem): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $item = $team->inventoryItems()->findOrFail($inventoryItem->id);

        Gate::authorize('updateImages', $item);

        $validated = $request->validate([
            'image_ids' => ['sometimes', 'array', 'max:10'],
            'image_ids.*' => ['required', 'uuid', 'distinct'],
            'new_image_ids' => ['sometimes', 'array', 'max:10'],
            'new_image_ids.*' => ['required', 'uuid', 'distinct'],
            'new_images' => ['sometimes', 'array', 'max:10'],
            'new_images.*' => ['file', 'image', 'max:10240'],
            'main_image_id' => ['nullable', 'uuid'],
        ]);

        $imageIds = $validated['image_ids'] ?? [];
        $newImageIds = $validated['new_image_ids'] ?? [];
        $newImageFiles = $request->file('new_images', []);

        abort_unless(count($newImageIds) === count($newImageFiles), 422);
        abort_if(array_diff($newImageIds, $imageIds) !== [], 422);
        abort_if(
            isset($validated['main_image_id']) && ! in_array($validated['main_image_id'], $imageIds, true),
            422,
        );

        $existingImages = collect($item->images ?? [])->keyBy('id');
        $newFilesById = collect($newImageFiles)->keyBy(fn ($file, int $index): string => $newImageIds[$index]);
        $removedPaths = $existingImages
            ->reject(fn (array $image, string $id): bool => in_array($id, $imageIds, true))
            ->map(fn (array $image): ?string => $image['path'] ?? $this->storagePathFromUrl($image['src']))
            ->filter()
            ->values()
            ->all();
        $storedPaths = [];

        try {
            DB::transaction(function () use ($item, $team, $imageIds, $newImageIds, $newFilesById, &$storedPaths, $validated, $request): void {
                $images = [];

                foreach ($imageIds as $id) {
                    if (($existingImage = collect($item->images ?? [])->firstWhere('id', $id)) !== null) {
                        $images[] = $existingImage;

                        continue;
                    }

                    abort_unless(in_array($id, $newImageIds, true), 422);
                    $imageFile = $newFilesById->get($id);
                    abort_unless($imageFile !== null, 422);

                    $path = $imageFile->store('inventory/'.$item->team_id, 'public');
                    abort_unless($path !== false, 500);
                    $storedPaths[] = $path;
                    $images[] = [
                        'id' => $id,
                        'name' => $imageFile->getClientOriginalName(),
                        'src' => Storage::disk('public')->url($path),
                        'path' => $path,
                    ];
                }

                $item->update([
                    'images' => $images,
                    'main_image_id' => $validated['main_image_id'] ?? null,
                ]);

                activity('inventory')
                    ->causedBy($request->user())
                    ->withProperties([
                        'team_id' => $team->id,
                        'item_name' => $item->name,
                        'sku' => $item->sku,
                        'image_names' => array_column($images, 'name'),
                    ])
                    ->log('Inventory Images Updated');
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);

            throw $exception;
        }

        Storage::disk('public')->delete($removedPaths);

        return back();
    }

    /**
     * Convert an inventory model into the frontend shape.
     *
     * @return array<string, mixed>
     */
    private function toArray(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'category' => $item->category ?? '',
            'quantity' => $item->quantity,
            'reorderPoint' => $item->reorder_point,
            'unitCost' => (float) $item->unit_cost,
            'stock' => $item->stockLevels
                ->map(fn (StockLevel $level): array => [
                    'warehouseId' => $level->warehouse_id,
                    'warehouseName' => $level->warehouse?->name,
                    'warehouseCode' => $level->warehouse?->code,
                    'locationId' => $level->warehouse_location_id,
                    'locationName' => $level->location?->name,
                    'locationCode' => $level->location?->code,
                    'quantity' => $level->quantity,
                ])
                ->values()
                ->all(),
            'images' => collect($item->images ?? [])
                ->map(fn (array $image): array => [
                    'id' => $image['id'],
                    'name' => $image['name'],
                    'src' => $image['src'],
                ])
                ->all(),
            'mainImageId' => $item->main_image_id,
            'createdAt' => $item->created_at?->toIso8601String(),
            'updatedAt' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Convert a stock movement into the frontend shape.
     *
     * @return array<string, mixed>
     */
    private function movementToArray(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'typeLabel' => $movement->type->label(),
            'itemName' => $movement->item_name,
            'sku' => $movement->sku,
            'warehouseName' => $movement->warehouse?->name,
            'locationName' => $movement->location?->name,
            'quantity' => $movement->quantity,
            'quantityBefore' => $movement->quantity_before,
            'quantityAfter' => $movement->quantity_after,
            'unitCost' => (float) $movement->unit_cost,
            'reference' => $movement->reference,
            'reason' => $movement->reason,
            'userName' => $movement->user?->name ?? 'System',
            'createdAt' => $movement->created_at?->toIso8601String(),
        ];
    }

    /**
     * Build the warehouse and bin options used by the inventory screens.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function warehouseOptions(Team $team): Collection
    {
        return $team->warehouses()
            ->with(['locations' => fn ($query) => $query
                ->withSum('stockLevels as total_units', 'quantity')
                ->orderByDesc('is_default')
                ->orderBy('name')])
            ->withSum('stockLevels as total_units', 'quantity')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'isDefault' => $warehouse->is_default,
                'totalUnits' => (int) ($warehouse->total_units ?? 0),
                'locations' => $warehouse->locations
                    ->map(fn ($location): array => [
                        'id' => $location->id,
                        'code' => $location->code,
                        'name' => $location->name,
                        'isDefault' => $location->is_default,
                        'totalUnits' => (int) ($location->total_units ?? 0),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    /**
     * Get a public disk path from a previously stored image URL.
     */
    private function storagePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
            return null;
        }

        return substr($path, strlen('/storage/'));
    }
}
