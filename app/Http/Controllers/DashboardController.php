<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    /**
     * Display the dashboard for the current team.
     */
    public function __invoke(Request $request): Response
    {
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        $team = $request->user()->currentTeam;

        $summary = [
            'totalItems' => 0,
            'totalUnits' => 0,
            'lowStockCount' => 0,
            'outOfStockCount' => 0,
            'inventoryValue' => 0.0,
        ];
        $reorderItems = [];
        $activities = [];
        $movement = $this->emptyMovement();

        if ($team !== null) {
            $summary = [
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
            ];

            $reorderItems = $team->inventoryItems()
                ->where('quantity', '>', 0)
                ->whereColumn('quantity', '<=', 'reorder_point')
                ->orderBy('quantity')
                ->orderBy('id')
                ->limit(5)
                ->get()
                ->map(fn (InventoryItem $item): array => [
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'reorderPoint' => $item->reorder_point,
                ])
                ->values()
                ->all();

            $activities = $this->inventoryActivities($team->id)
                ->with('causer')
                ->latest()
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(function (Activity $activity): array {
                    $itemName = $activity->getProperty('item_name');
                    $sku = $activity->getProperty('sku');
                    $causer = $activity->causer;

                    return [
                        'id' => $activity->id,
                        'description' => $activity->description,
                        'itemName' => is_string($itemName) ? $itemName : '',
                        'sku' => is_string($sku) ? $sku : '',
                        'userName' => $causer instanceof User ? $causer->name : 'System',
                        'createdAt' => $activity->created_at?->toIso8601String() ?? '',
                    ];
                })
                ->values()
                ->all();

            $movement = $this->movement($team->id);
        }

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'summary' => $summary,
            'reorderItems' => $reorderItems,
            'activities' => $activities,
            'movement' => $movement,
        ]);
    }

    /**
     * Query inventory activity rows that belong to the given team.
     *
     * @return Builder<Activity>
     */
    private function inventoryActivities(int $teamId): Builder
    {
        return Activity::query()
            ->where('log_name', 'inventory')
            ->where(fn (Builder $query) => $query
                ->where('properties->team_id', $teamId)
                ->orWhere('properties->team_id', (string) $teamId));
    }

    /**
     * Build the seven-day stock movement series for the given team.
     *
     * @return array<int, array{day: string, received: int, dispatched: int}>
     */
    private function movement(int $teamId): array
    {
        $today = now()->startOfDay();

        $counts = StockMovement::query()
            ->where('team_id', $teamId)
            ->where('type', '!=', StockMovementType::Opening->value)
            ->where('created_at', '>=', $today->copy()->subDays(6))
            ->get(['quantity', 'created_at'])
            ->groupBy(fn (StockMovement $movement): string => $movement->created_at->toDateString())
            ->map(fn ($dayMovements): array => [
                'received' => (int) $dayMovements
                    ->where('quantity', '>', 0)
                    ->sum('quantity'),
                'dispatched' => abs((int) $dayMovements
                    ->where('quantity', '<', 0)
                    ->sum('quantity')),
            ]);

        return collect(range(6, 0))
            ->map(function (int $offset) use ($counts, $today): array {
                $date = $today->copy()->subDays($offset);
                $day = $counts->get($date->toDateString());

                return [
                    'day' => $date->format('D'),
                    'received' => (int) ($day['received'] ?? 0),
                    'dispatched' => (int) ($day['dispatched'] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * Build an empty seven-day stock movement series.
     *
     * @return array<int, array{day: string, received: int, dispatched: int}>
     */
    private function emptyMovement(): array
    {
        return collect(range(6, 0))
            ->map(fn (int $offset): array => [
                'day' => now()->startOfDay()->subDays($offset)->format('D'),
                'received' => 0,
                'dispatched' => 0,
            ])
            ->all();
    }
}
