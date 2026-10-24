<?php

namespace App\Http\Controllers\Suppliers;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    /**
     * Display the active suppliers for the current team.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewAny', Supplier::class);

        return Inertia::render('suppliers/Index', [
            'suppliers' => $team->suppliers()
                ->orderBy('name')
                ->get()
                ->map(fn (Supplier $supplier): array => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'contactName' => $supplier->contact_name ?? '',
                    'email' => $supplier->email ?? '',
                    'phone' => $supplier->phone ?? '',
                    'billingAddress' => $supplier->billing_address ?? '',
                    'isActive' => $supplier->is_active,
                ]),
            'canManage' => $request->user()->can('create', Supplier::class),
        ]);
    }

    /**
     * Store a new supplier for the current team.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('create', Supplier::class);

        $supplier = $team->suppliers()->create($this->validatedSupplier($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Supplier :name created.', ['name' => $supplier->name]),
        ]);

        return back();
    }

    /**
     * Update a supplier belonging to the current team.
     */
    public function update(Request $request, string $currentTeamSlug, Supplier $supplier): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $supplier = $team->suppliers()->findOrFail($supplier->id);

        Gate::authorize('update', $supplier);

        $supplier->update($this->validatedSupplier($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Supplier updated.'),
        ]);

        return back();
    }

    /**
     * Archive a supplier belonging to the current team.
     */
    public function destroy(Request $request, string $currentTeamSlug, Supplier $supplier): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $supplier = $team->suppliers()->findOrFail($supplier->id);

        Gate::authorize('delete', $supplier);

        $hasOpenPurchaseOrders = $supplier->purchaseOrders()
            ->whereNotIn('status', [
                PurchaseOrderStatus::Received->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->exists();

        if ($hasOpenPurchaseOrders) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This supplier has open purchase orders and cannot be archived.'),
            ]);

            return back();
        }

        $supplier->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Supplier archived.'),
        ]);

        return to_route('suppliers.index', ['current_team' => $team->slug]);
    }

    /**
     * Validate and normalize supplier contact details.
     *
     * @return array{name: string, contact_name: ?string, email: ?string, phone: ?string, billing_address: ?string, is_active: bool}
     */
    private function validatedSupplier(Request $request): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'contact_name' => trim((string) $request->input('contact_name')) ?: null,
            'email' => trim((string) $request->input('email')) ?: null,
            'phone' => trim((string) $request->input('phone')) ?: null,
            'billing_address' => trim((string) $request->input('billing_address')) ?: null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => $validated['name'],
            'contact_name' => $validated['contact_name'] ?: null,
            'email' => $validated['email'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'billing_address' => $validated['billing_address'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }
}
