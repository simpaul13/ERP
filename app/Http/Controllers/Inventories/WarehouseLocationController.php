<?php

namespace App\Http\Controllers\Inventories;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WarehouseLocationController extends Controller
{
    /**
     * Store a new bin in the given warehouse.
     */
    public function store(Request $request, string $currentTeamSlug, Warehouse $warehouse): RedirectResponse
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
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $warehouse->id),
            ],
        ]);

        $location = $warehouse->locations()->create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: $this->uniqueCode($warehouse, $validated['name']),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Bin :name added.', ['name' => $location->name]),
        ]);

        return back();
    }

    /**
     * Update the given bin.
     */
    public function update(
        Request $request,
        string $currentTeamSlug,
        Warehouse $warehouse,
        WarehouseLocation $location,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        $warehouse = $team->warehouses()->findOrFail($warehouse->id);
        $location = $warehouse->locations()->findOrFail($location->id);

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
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $warehouse->id)
                    ->ignore($location->id),
            ],
        ]);

        $location->update([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: $this->uniqueCode($warehouse, $validated['name'], $location->id),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Bin updated.'),
        ]);

        return back();
    }

    /**
     * Remove the given bin.
     */
    public function destroy(
        Request $request,
        string $currentTeamSlug,
        Warehouse $warehouse,
        WarehouseLocation $location,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        $warehouse = $team->warehouses()->findOrFail($warehouse->id);
        $location = $warehouse->locations()->findOrFail($location->id);

        Gate::authorize('update', $warehouse);

        if ($location->is_default) {
            throw ValidationException::withMessages([
                'location' => 'The default bin cannot be removed.',
            ]);
        }

        if ($location->stockLevels()->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'location' => 'Move the remaining stock before removing this bin.',
            ]);
        }

        $location->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Bin removed.'),
        ]);

        return back();
    }

    /**
     * Build a unique bin code from a display name.
     */
    private function uniqueCode(Warehouse $warehouse, string $name, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::upper(Str::slug($name, '-')), 45, '') ?: 'BIN';
        $code = $base;
        $suffix = 2;

        while (WarehouseLocation::withTrashed()
            ->where('warehouse_id', $warehouse->id)
            ->where('code', $code)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $code = $base.'-'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
