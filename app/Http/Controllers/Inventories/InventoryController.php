<?php

namespace App\Http\Controllers\Inventories;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Display inventory items for the current team.
     */
    public function index(Request $request): Response
    {
        $items = $request->user()->currentTeam->inventoryItems()
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryItem $item): array => $this->toArray($item));

        return Inertia::render('inventories/Index', [
            'inventoryItems' => $items,
        ]);
    }

    /**
     * Store a new inventory item for the current team.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
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
                        'item_name' => $item->name,
                        'sku' => $item->sku,
                        'image_names' => array_column($images, 'name'),
                    ])
                    ->log('Inventory Create');

            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);

            throw $exception;
        }

        return to_route('inventories.index', ['current_team' => $team->slug]);
    }

    /**
     * Persist product image changes for an inventory item.
     */
    public function updateImages(Request $request, string $currentTeamSlug, InventoryItem $inventoryItem): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $item = $team->inventoryItems()->findOrFail($inventoryItem->id);
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
            ->map(fn (array $image): ?string => $image['path'] ?? $this->storagePathFromUrl($image['src'] ?? ''))
            ->filter()
            ->values()
            ->all();
        $storedPaths = [];

        try {
            DB::transaction(function () use ($item, $imageIds, $newImageIds, $newFilesById, &$storedPaths, $validated, $request): void {
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
                        'item_name' => $item->name,
                        'sku' => $item->sku,
                        'image_names' => array_column($images, 'name'),
                    ])
                    ->log('Inventory Update');
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);

            throw $exception;
        }

        Storage::disk('public')->delete($removedPaths);

        return to_route('inventories.index', ['current_team' => $team->slug]);
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
