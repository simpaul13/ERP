<?php

namespace App\Http\Controllers\Inventories;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        $filters = [
            'search' => (string) $request->string('search')->trim(),
            'status' => in_array($status, ['low', 'out'], true) ? $status : 'all',
        ];

        $inventoryItems = $team->inventoryItems()
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = '%'.$filters['search'].'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', $search)
                        ->orWhere('sku', 'like', $search)
                        ->orWhere('category', 'like', $search)
                        ->orWhere('location', 'like', $search);
                });
            })
            ->when($filters['status'] === 'low', fn ($query) => $query
                ->where('quantity', '>', 0)
                ->whereColumn('quantity', '<=', 'reorder_point'))
            ->when($filters['status'] === 'out', fn ($query) => $query
                ->where('quantity', 0))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (InventoryItem $item): array => $this->toArray($item));

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
     * Store a new inventory item for the current team.
     */
    public function store(Request $request): RedirectResponse
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
            'location' => ['nullable', 'string', 'max:255'],
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
            DB::transaction(function () use ($team, $validated, $imageFiles, $imageIds, $request, &$storedPaths): void {
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
                    'location' => $validated['location'] ?? null,
                    'quantity' => $validated['quantity'],
                    'reorder_point' => $validated['reorder_point'],
                    'unit_cost' => $validated['unit_cost'],
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
            'location' => ['nullable', 'string', 'max:255'],
            'reorder_point' => ['required', 'integer', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $oldValues = $item->only([
            'name',
            'sku',
            'category',
            'location',
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
        $item = $team->inventoryItems()->findOrFail($inventoryItem->id);

        Gate::authorize('delete', $item);

        $images = collect($item->images ?? []);
        $imageNames = $images->pluck('name')->all();
        $imagePaths = $images
            ->map(fn (array $image): ?string => $image['path'] ?? $this->storagePathFromUrl($image['src']))
            ->filter()
            ->values()
            ->all();

        Storage::disk('public')->delete($imagePaths);

        activity('inventory')
            ->causedBy($request->user())
            ->withProperties([
                'team_id' => $team->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'image_names' => $imageNames,
            ])
            ->log('Inventory Deleted');

        $item->delete();

        return back();
    }

    /**
     * Adjust the stock quantity of an inventory item.
     */
    public function stock(Request $request, string $currentTeamSlug, InventoryItem $inventoryItem): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $item = $team->inventoryItems()->findOrFail($inventoryItem->id);

        Gate::authorize('adjust', $item);

        $validated = $request->validate([
            'direction' => ['required', Rule::in(['receive', 'remove'])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = (int) $validated['amount'];

        DB::transaction(function () use ($team, $inventoryItem, $validated, $request, $amount): void {
            $item = $team->inventoryItems()->lockForUpdate()->findOrFail($inventoryItem->id);
            $quantityBefore = $item->quantity;
            $quantityAfter = $validated['direction'] === 'receive'
                ? $quantityBefore + $amount
                : max(0, $quantityBefore - $amount);

            $item->update(['quantity' => $quantityAfter]);

            activity('inventory')
                ->causedBy($request->user())
                ->withProperties([
                    'team_id' => $team->id,
                    'item_name' => $item->name,
                    'sku' => $item->sku,
                    'direction' => $validated['direction'],
                    'amount' => $amount,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'reason' => $validated['reason'] ?? null,
                ])
                ->log('Inventory Stock Adjusted');
        });

        return back();
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
            'location' => $item->location ?? '',
            'quantity' => $item->quantity,
            'reorderPoint' => $item->reorder_point,
            'unitCost' => (float) $item->unit_cost,
            'images' => collect($item->images ?? [])
                ->map(fn (array $image): array => [
                    'id' => $image['id'],
                    'name' => $image['name'],
                    'src' => $image['src'],
                ])
                ->all(),
            'mainImageId' => $item->main_image_id,
        ];
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
