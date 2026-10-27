<?php

namespace App\Http\Controllers\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsReportController extends Controller
{
    private const REPORT_PAGE_SIZE = 50;

    public function overview(Request $request): Response
    {
        $team = $this->team($request);

        return Inertia::render('Reports/Overview', [
            'lowStockItems' => $team->inventoryItems()->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'reorder_point')->get(['id', 'sku', 'name', 'quantity', 'reorder_point']),
            'outOfStockItems' => $team->inventoryItems()->where('quantity', 0)->get(['id', 'sku', 'name', 'quantity']),
            'pendingSalesOrders' => $team->salesOrders()->whereNotIn('status', [SalesOrderStatus::Fulfilled->value, SalesOrderStatus::Cancelled->value])->latest()->get(),
            'pendingPurchaseOrders' => $team->purchaseOrders()->with('supplier')->whereNotIn('status', [PurchaseOrderStatus::Received->value, PurchaseOrderStatus::Cancelled->value])->latest()->get(),
            'activities' => DB::table('activity_log')->where('log_name', 'inventory')->where(function ($query) use ($team): void {
                $query->where('properties->team_id', $team->id)->orWhere('properties->team_id', (string) $team->id);
            })->latest()->limit(10)->get(),
        ]);
    }

    public function inventory(Request $request): Response|StreamedResponse
    {
        $filters = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'], 'export' => ['nullable', 'boolean']]);
        $team = $this->team($request);
        $query = StockLevel::query()->where('team_id', $team->id)->with(['inventoryItem', 'warehouse'])->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))->when($filters['item_id'] ?? null, fn ($q, $id) => $q->where('inventory_item_id', $id))->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas('inventoryItem', fn ($items) => $items->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")));
        $row = fn (StockLevel $level): array => ['itemId' => $level->inventory_item_id, 'sku' => $level->inventoryItem->sku, 'itemName' => $level->inventoryItem->name, 'warehouseId' => $level->warehouse_id, 'warehouseName' => $level->warehouse->name, 'quantity' => $level->quantity, 'lowStock' => $level->inventoryItem->quantity > 0 && $level->inventoryItem->quantity <= $level->inventoryItem->reorder_point];

        if ($filters['export'] ?? false) {
            $rows = $query->get()->map($row)->all();

            return $this->csv('inventory.csv', ['item_id', 'sku', 'item_name', 'warehouse_id', 'warehouse', 'quantity', 'low_stock'], array_map(fn ($result) => [$result['itemId'], $result['sku'], $result['itemName'], $result['warehouseId'], $result['warehouseName'], $result['quantity'], $result['lowStock']], $rows));
        }

        $levels = $query->orderBy('id')->paginate(self::REPORT_PAGE_SIZE)->withQueryString();

        return Inertia::render('Reports/Inventory', ['rows' => $levels->getCollection()->map($row)->all(), 'pagination' => $this->pagination($levels), 'filters' => $filters, 'warehouses' => $team->warehouses()->orderBy('name')->get(['id', 'name']), 'items' => $team->inventoryItems()->orderBy('name')->get(['id', 'name', 'sku'])]);
    }

    public function movements(Request $request): Response|StreamedResponse
    {
        $request->merge($this->defaultDateRange($request));
        $filters = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'warehouse_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer'], 'type' => ['nullable', 'string', 'in:'.implode(',', StockMovementType::values())], 'export' => ['nullable', 'boolean']]);
        $team = $this->team($request);
        $query = StockMovement::query()->where('team_id', $team->id)->whereBetween('created_at', [$filters['from'], $filters['to'].' 23:59:59'])->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))->when($filters['item_id'] ?? null, fn ($q, $id) => $q->where('inventory_item_id', $id))->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))->with('warehouse')->orderBy('created_at')->orderBy('id');
        $row = fn (StockMovement $movement): array => ['id' => $movement->id, 'date' => $movement->created_at?->toIso8601String(), 'sku' => $movement->sku, 'itemName' => $movement->item_name, 'type' => $movement->type->value, 'quantity' => $movement->quantity, 'received' => $movement->quantity > 0 ? $movement->quantity : 0, 'dispatched' => $movement->quantity < 0 ? abs($movement->quantity) : 0, 'warehouse' => $movement->warehouse->name];

        if ($filters['export'] ?? false) {
            $rows = $query->get()->map($row)->all();

            return $this->csv('stock-movements.csv', ['id', 'date', 'sku', 'item_name', 'type', 'quantity', 'received', 'dispatched', 'warehouse'], array_map(fn ($result) => array_values($result), $rows));
        }

        $movements = $query->paginate(self::REPORT_PAGE_SIZE)->withQueryString();

        return Inertia::render('Reports/StockMovements', ['rows' => $movements->getCollection()->map($row)->all(), 'pagination' => $this->pagination($movements), 'filters' => $filters, 'warehouses' => $team->warehouses()->orderBy('name')->get(['id', 'name']), 'items' => $team->inventoryItems()->orderBy('name')->get(['id', 'name', 'sku']), 'movementTypes' => $this->enumOptions(StockMovementType::cases())]);
    }

    public function sales(Request $request): Response|StreamedResponse
    {
        $request->merge($this->defaultDateRange($request));
        $filters = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'status' => ['nullable', 'string', 'in:'.implode(',', array_column(SalesOrderStatus::cases(), 'value'))], 'export' => ['nullable', 'boolean']]);
        $query = $this->dated($this->team($request)->salesOrders(), $filters)->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $summary = $query->select('status', 'currency')->selectRaw('COUNT(*) as count, SUM(total_amount) as total')->groupBy('status', 'currency')->orderBy('status')->orderBy('currency');

        if ($filters['export'] ?? false) {
            $rows = $summary->get()->map(fn ($row): array => [$row->status->value, $row->currency, $row->count, $row->total])->all();

            return $this->csv('sales-summary.csv', ['status', 'currency', 'count', 'total'], $rows);
        }

        $page = $summary->paginate(self::REPORT_PAGE_SIZE)->withQueryString();

        return Inertia::render('Reports/SalesSummary', ['rows' => $page->getCollection()->map(fn ($row): array => ['status' => $row->status->value, 'currency' => $row->currency, 'count' => (int) $row->count, 'total' => (float) $row->total])->all(), 'pagination' => $this->pagination($page), 'filters' => $filters, 'statuses' => $this->enumOptions(SalesOrderStatus::cases())]);
    }

    public function purchases(Request $request): Response|StreamedResponse
    {
        $team = $this->team($request);
        $request->merge($this->defaultDateRange($request));
        $filters = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'status' => ['nullable', 'string', 'in:'.implode(',', array_column(PurchaseOrderStatus::cases(), 'value'))], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id,team_id,'.$team->id], 'export' => ['nullable', 'boolean']]);
        $query = $this->dated($team->purchaseOrders(), $filters)->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id));
        $summary = $query->select('status')->selectRaw("'PHP' as currency, COUNT(*) as count, SUM(total_amount) as total")->groupBy('status')->orderBy('status');

        if ($filters['export'] ?? false) {
            $rows = $summary->get()->map(fn ($row): array => [$row->status->value, $row->currency, $row->count, $row->total])->all();

            return $this->csv('purchase-summary.csv', ['status', 'currency', 'count', 'total'], $rows);
        }

        $page = $summary->paginate(self::REPORT_PAGE_SIZE)->withQueryString();

        return Inertia::render('Reports/PurchaseSummary', ['rows' => $page->getCollection()->map(fn ($row): array => ['status' => $row->status->value, 'currency' => $row->currency, 'count' => (int) $row->count, 'total' => (float) $row->total])->all(), 'pagination' => $this->pagination($page), 'filters' => $filters, 'suppliers' => $team->suppliers()->orderBy('name')->get(['id', 'name']), 'statuses' => $this->enumOptions(PurchaseOrderStatus::cases())]);
    }

    private function team(Request $request): Team
    {
        abort_unless($request->user()->currentTeam instanceof Team, 403);

        return $request->user()->currentTeam;
    }

    /** @return array{from: string, to: string} */
    private function defaultDateRange(Request $request): array
    {
        return [
            'from' => $request->input('from', now()->startOfMonth()->toDateString()),
            'to' => $request->input('to', now()->toDateString()),
        ];
    }

    /** @param Builder<Model> $query @param array<string, string> $filters */
    private function dated(Builder|HasMany $query, array $filters): Builder|HasMany
    {
        return $query->whereDate('created_at', '>=', $filters['from'])->whereDate('created_at', '<=', $filters['to']);
    }

    /** @param LengthAwarePaginator<int, mixed> $page @return array{current_page: int, last_page: int, from: int|null, to: int|null, total: int, links: array<int, array{url: string|null, label: string, active: bool}>} */
    private function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'total' => $page->total(),
            'links' => $page->linkCollection()->map(fn (array $link): array => ['url' => $link['url'], 'label' => $link['label'], 'active' => $link['active']])->all(),
        ];
    }

    /** @param array<int, \BackedEnum> $cases @return array<int, array{value: string, label: string}> */
    private function enumOptions(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): array => ['value' => $case->value, 'label' => $case instanceof StockMovementType || $case instanceof SalesOrderStatus || $case instanceof PurchaseOrderStatus ? $case->label() : $case->value], $cases);
    }

    /** @param array<int, string> $headers @param array<int, array<int, mixed>> $rows */
    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            } fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
