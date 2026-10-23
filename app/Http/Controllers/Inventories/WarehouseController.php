<?php

namespace App\Http\Controllers\Inventories;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\Warehouse;
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

        return back();
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
