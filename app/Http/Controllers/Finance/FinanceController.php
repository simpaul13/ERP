<?php

namespace App\Http\Controllers\Finance;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team && $request->user()->teamRole($team), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(['income', 'expense'])],
            'category' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->endOfMonth()->toDateString();
        $transactions = $team->financeTransactions()->with('category')
            ->whereBetween('transaction_date', [$from, $to])
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('finance_category_id', $category))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('description', 'like', '%'.$search.'%'))
            ->orderByDesc('transaction_date')->orderByDesc('id')->get();
        $allTransactions = $team->financeTransactions()->whereBetween('transaction_date', [$from, $to]);

        return Inertia::render('finance/Index', [
            'transactions' => $transactions->map(fn (FinanceTransaction $transaction): array => [
                'id' => $transaction->id,
                'date' => $transaction->transaction_date->toDateString(),
                'description' => $transaction->description,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'paymentMethod' => $transaction->payment_method,
                'categoryId' => $transaction->finance_category_id,
                'category' => $transaction->category->name,
            ]),
            'categories' => $team->financeCategories()->orderBy('type')->orderBy('name')->get()->map(fn (FinanceCategory $category): array => [
                'id' => $category->id, 'name' => $category->name, 'type' => $category->type, 'isActive' => $category->is_active,
            ]),
            'totals' => [
                'income' => (clone $allTransactions)->where('type', 'income')->sum('amount'),
                'expenses' => (clone $allTransactions)->where('type', 'expense')->sum('amount'),
            ],
            'filters' => ['from' => $from, 'to' => $to, 'type' => $filters['type'] ?? '', 'category' => $filters['category'] ?? '', 'search' => $filters['search'] ?? ''],
            'canManage' => $request->user()->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('manage-finance', $team);
        $team->financeTransactions()->create($this->validatedTransaction($request, $team->id));

        return back();
    }

    public function update(Request $request, string $currentTeamSlug, FinanceTransaction $financeTransaction): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $financeTransaction = $team->financeTransactions()->findOrFail($financeTransaction->id);
        Gate::authorize('manage-finance', $team);
        $financeTransaction->update($this->validatedTransaction($request, $team->id));

        return back();
    }

    public function destroy(Request $request, string $currentTeamSlug, FinanceTransaction $financeTransaction): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $financeTransaction = $team->financeTransactions()->findOrFail($financeTransaction->id);
        Gate::authorize('manage-finance', $team);
        $financeTransaction->delete();

        return back();
    }

    public function saveCategory(Request $request, ?string $currentTeamSlug = null, ?FinanceCategory $financeCategory = null): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('manage-finance', $team);
        $category = $financeCategory ? $team->financeCategories()->findOrFail($financeCategory->id) : null;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $duplicate = $team->financeCategories()->where('type', $data['type'])->where('name', $data['name'])->when($category, fn ($query) => $query->whereKeyNot($category->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'A category with this name already exists for this type.']);
        }
        if ($category) {
            $category->update($data);
        } else {
            $team->financeCategories()->create($data + ['is_active' => true]);
        }

        return back();
    }

    private function validatedTransaction(Request $request, int $teamId): array
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'payment_method' => ['required', Rule::in(['cash', 'bank'])],
            'finance_category_id' => ['required', 'integer', Rule::exists('finance_categories', 'id')->where('team_id', $teamId)->where('is_active', true)->where('type', $request->input('type'))],
        ]);

        return $data;
    }
}
