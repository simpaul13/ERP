<?php

namespace App\Http\Controllers\Inventories;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Team;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    /**
     * Display the warehouses and bins for the current team.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewAny', Warehouse::class);

        $warehouses = $team->warehouses()
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
                'address' => $warehouse->address ?? '',
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
            ]);

        return Inertia::render('inventories/Warehouses', [
            'warehouses' => $warehouses,
            'canManage' => $request->user()->can('create', Warehouse::class),
            'defaultWarehouseId' => $team->defaultWarehouse()?->id,
        ]);
    }

    /**
     * Display the profile for the given warehouse.
     */
    public function show(Request $request, string $currentTeamSlug, Warehouse $warehouse): Response
    {
        $team = $request->user()->currentTeam;
        $warehouse = $team->warehouses()->findOrFail($warehouse->id);

        Gate::authorize('view', $warehouse);

        $bins = $warehouse->locations()
            ->withSum('stockLevels as total_units', 'quantity')
            ->withCount([
                'stockLevels as stocked_items' => fn ($query) => $query
                    ->where('quantity', '>', 0),
            ])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (WarehouseLocation $location): array => [
                'id' => $location->id,
                'code' => $location->code,
                'name' => $location->name,
                'isDefault' => $location->is_default,
                'totalUnits' => (int) ($location->total_units ?? 0),
                'stockedItems' => (int) $location->stocked_items,
            ])
            ->values();

        $items = $team->inventoryItems()
            ->whereHas('stockLevels', fn ($query) => $query
                ->where('warehouse_id', $warehouse->id)
                ->where('quantity', '>', 0))
            ->with(['stockLevels' => fn ($query) => $query
                ->where('warehouse_id', $warehouse->id)
                ->where('quantity', '>', 0)
                ->with('location')
                ->orderBy('warehouse_location_id')])
            ->orderBy('name')
            ->get()
            ->map(function (InventoryItem $item): array {
                $quantity = (int) $item->stockLevels->sum('quantity');
                $mainImage = collect($item->images ?? [])
                    ->firstWhere('id', $item->main_image_id);

                return [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'category' => $item->category ?? '',
                    'quantity' => $quantity,
                    'reorderPoint' => $item->reorder_point,
                    'unitCost' => (float) $item->unit_cost,
                    'value' => $quantity * (float) $item->unit_cost,
                    'status' => $quantity <= $item->reorder_point ? 'low' : 'in',
                    'mainImageSrc' => is_array($mainImage)
                        ? ($mainImage['src'] ?? null)
                        : null,
                    'bins' => $item->stockLevels
                        ->map(fn (StockLevel $level): array => [
                            'id' => $level->location?->id,
                            'name' => $level->location?->name,
                            'code' => $level->location?->code,
                            'quantity' => $level->quantity,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        $movements = $warehouse->stockMovements()
            ->with(['location', 'user'])
            ->latest()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type->value,
                'typeLabel' => $movement->type->label(),
                'itemName' => $movement->item_name,
                'sku' => $movement->sku,
                'locationName' => $movement->location?->name,
                'quantity' => $movement->quantity,
                'quantityAfter' => $movement->quantity_after,
                'unitCost' => (float) $movement->unit_cost,
                'reference' => $movement->reference,
                'reason' => $movement->reason,
                'userName' => $movement->user?->name ?? 'System',
                'createdAt' => $movement->created_at?->toIso8601String(),
            ]);

        return Inertia::render('inventories/WarehouseProfile', [
            'warehouse' => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'address' => $warehouse->address ?? '',
                'isDefault' => $warehouse->is_default,
                'createdAt' => $warehouse->created_at?->toIso8601String(),
            ],
            'summary' => [
                'totalUnits' => (int) $items->sum('quantity'),
                'totalBins' => $bins->count(),
                'stockedItems' => $items->count(),
                'lowStockItems' => $items->where('status', 'low')->count(),
                'inventoryValue' => (float) $items->sum('value'),
            ],
            'bins' => $bins,
            'items' => $items,
            'movements' => $movements,
            'canManage' => $request->user()->can('update', $warehouse),
        ]);
    }

    /**
     * Store a new warehouse for the current team.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('create', Warehouse::class);

        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')->where('team_id', $team->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $warehouse = $team->warehouses()->create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: $this->uniqueCode($team, $validated['name']),
            'address' => $validated['address'] ?? null,
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Warehouse :name created.', ['name' => $warehouse->name]),
        ]);

        return back();
    }

    /**
     * Update the given warehouse.
     */
    public function update(Request $request, string $currentTeamSlug, Warehouse $warehouse): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $warehouse = $team->warehouses()->findOrFail($warehouse->id);

        Gate::authorize('update', $warehouse);

        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('team_id', $team->id)
                    ->ignore($warehouse->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $warehouse->update([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: $this->uniqueCode($team, $validated['name'], $warehouse->id),
            'address' => $validated['address'] ?? null,
            'is_default' => $request->has('is_default')
                ? (bool) ($validated['is_default'] ?? false)
                : $warehouse->is_default,
        ]);

        $this->ensureDefaultWarehouse($team, $warehouse);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Warehouse updated.'),
        ]);

        return back();
    }

    /**
     * Archive the given warehouse.
     */
    public function destroy(Request $request, string $currentTeamSlug, Warehouse $warehouse): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $warehouse = $team->warehouses()->findOrFail($warehouse->id);

        Gate::authorize('delete', $warehouse);

        if ($team->warehouses()->count() <= 1) {
            throw ValidationException::withMessages([
                'warehouse' => 'A team must keep at least one warehouse.',
            ]);
        }

        if ($warehouse->stockLevels()->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'warehouse' => 'Move the remaining stock before archiving this warehouse.',
            ]);
        }

        $wasDefault = $warehouse->is_default;
        $warehouse->delete();

        if ($wasDefault) {
            $team->warehouses()->orderBy('id')->first()?->update(['is_default' => true]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Warehouse archived.'),
        ]);

        return to_route('warehouses.index', ['current_team' => $team->slug]);
    }

    /**
     * Make sure the team always has exactly one default warehouse.
     */
    private function ensureDefaultWarehouse(Team $team, Warehouse $warehouse): void
    {
        if ($team->warehouses()->where('is_default', true)->exists()) {
            return;
        }

        $fallback = $team->warehouses()
            ->whereKeyNot($warehouse->id)
            ->orderBy('id')
            ->first()
            ?? $warehouse;

        $fallback->update(['is_default' => true]);
    }

    /**
     * Build a unique warehouse code from a display name.
     */
    private function uniqueCode(Team $team, string $name, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::upper(Str::slug($name, '-')), 45, '') ?: 'WH';
        $code = $base;
        $suffix = 2;

        while (Warehouse::withTrashed()
            ->where('team_id', $team->id)
            ->where('code', $code)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $code = $base.'-'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
