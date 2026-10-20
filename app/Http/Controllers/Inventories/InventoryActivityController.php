<?php

namespace App\Http\Controllers\Inventories;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InventoryActivityController
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['Create', 'Update', 'Upload Image'])],
            'item_name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'image_names' => ['sometimes', 'array', 'max:10'],
            'image_names.*' => ['string', 'max:255'],
        ]);

        activity('inventory')
            ->causedBy($request->user())
            ->withProperties([
                'item_name' => $validated['item_name'],
                'sku' => $validated['sku'] ?? null,
                'image_names' => $validated['image_names'] ?? [],
            ])
            ->log('Inventory '.$validated['action']);

        return response()->noContent();
    }
}
