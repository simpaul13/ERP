<?php

use App\Enums\TeamRole;
use App\Models\InventoryItem;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Carbon\CarbonInterface;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard summary reflects the current team inventory', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    createDashboardInventoryItem($team, 'A-100', 10, 5, 2.50);
    createDashboardInventoryItem($team, 'B-200', 3, 5, 10.00);
    createDashboardInventoryItem($team, 'C-300', 0, 4, 5.00);
    createDashboardInventoryItem($team, 'D-400', 5, 5, 1.00);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('summary.totalItems', 4)
        ->where('summary.totalUnits', 18)
        ->where('summary.lowStockCount', 2)
        ->where('summary.outOfStockCount', 1)
        ->where('summary.inventoryValue', 60),
    );
});

test('dashboard counts low stock and out of stock items', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    createDashboardInventoryItem($team, 'A-100', 0, 0);
    createDashboardInventoryItem($team, 'B-200', 1, 1);
    createDashboardInventoryItem($team, 'C-300', 2, 1);
    createDashboardInventoryItem($team, 'D-400', 5, 10);
    createDashboardInventoryItem($team, 'E-500', 0, 5);

    $otherTeam = Team::factory()->create();
    createDashboardInventoryItem($otherTeam, 'OTHER-1', 0, 0);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('summary.totalItems', 5)
        ->where('summary.lowStockCount', 2)
        ->where('summary.outOfStockCount', 2),
    );
});

test('dashboard lists the first five reorder items by quantity', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    createDashboardInventoryItem($team, 'A-100', 4, 10);
    createDashboardInventoryItem($team, 'B-200', 2, 5);
    createDashboardInventoryItem($team, 'C-300', 8, 8);
    createDashboardInventoryItem($team, 'D-400', 0, 10);
    createDashboardInventoryItem($team, 'E-500', 9, 5);
    createDashboardInventoryItem($team, 'F-600', 1, 10);
    createDashboardInventoryItem($team, 'G-700', 3, 10);
    createDashboardInventoryItem($team, 'H-800', 5, 10);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('reorderItems', 5)
        ->where('reorderItems.0.sku', 'F-600')
        ->where('reorderItems.0.name', 'Item F-600')
        ->where('reorderItems.0.quantity', 1)
        ->where('reorderItems.0.reorderPoint', 10)
        ->where('reorderItems.1.sku', 'B-200')
        ->where('reorderItems.2.sku', 'G-700')
        ->where('reorderItems.3.sku', 'A-100')
        ->where('reorderItems.4.sku', 'H-800')
        ->where('reorderItems', fn ($items) => collect($items)
            ->pluck('sku')
            ->doesntContain(['C-300', 'D-400', 'E-500'])),
    );
});

test('dashboard lists only the current teams latest five activities', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $team = $user->currentTeam;

    foreach (range(1, 4) as $index) {
        logDashboardInventoryActivity($team, $user, 'Inventory Update', [
            'item_name' => "Item {$index}",
            'sku' => "SKU-{$index}",
        ]);
    }

    logDashboardInventoryActivity($team, null, 'Inventory Update', [
        'item_name' => '',
        'sku' => 'SYS-1',
    ]);

    $latestActivity = logDashboardInventoryActivity($team, $user, 'Inventory Update', [
        'item_name' => 'Latest item',
        'sku' => 'LATEST-1',
    ]);

    $otherTeam = Team::factory()->create();
    logDashboardInventoryActivity($otherTeam, $user, 'Inventory Update', [
        'item_name' => 'Other team item',
        'sku' => 'OTHER-1',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('activities', 5)
        ->where('activities.0.itemName', 'Latest item')
        ->where('activities.0.sku', 'LATEST-1')
        ->where('activities.0.userName', 'Ada Lovelace')
        ->where('activities.0.createdAt', $latestActivity->fresh()->created_at->toIso8601String())
        ->where('activities.1.itemName', '')
        ->where('activities.1.sku', 'SYS-1')
        ->where('activities.1.userName', 'System')
        ->where('activities', fn ($activities) => collect($activities)
            ->pluck('itemName')
            ->doesntContain('Other team item')),
    );
});

test('dashboard movement counts stock adjustments over the last seven days', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $today = now()->startOfDay();

    logDashboardInventoryActivity($team, $user, 'Inventory Stock Adjusted', [
        'direction' => 'in',
        'amount' => 5,
    ], $today->copy()->addHours(9));

    logDashboardInventoryActivity($team, $user, 'Inventory Stock Adjusted', [
        'direction' => 'out',
        'amount' => 3,
    ], $today->copy()->addHours(10));

    logDashboardInventoryActivity($team, $user, 'Inventory Stock Adjusted', [
        'direction' => 'receive',
        'amount' => 7,
    ], $today->copy()->subDays(2)->addHours(9));

    logDashboardInventoryActivity($team, $user, 'Inventory Stock Adjusted', [
        'direction' => 'remove',
        'amount' => 2,
    ], $today->copy()->subDays(6)->addHours(9));

    logDashboardInventoryActivity($team, $user, 'Inventory Stock Adjusted', [
        'direction' => 'in',
        'amount' => 100,
    ], $today->copy()->subDays(7)->addHours(9));

    logDashboardInventoryActivity($team, $user, 'Inventory Update', [
        'direction' => 'in',
        'amount' => 50,
    ], $today->copy()->addHours(9));

    $otherTeam = Team::factory()->create();
    logDashboardInventoryActivity($otherTeam, $user, 'Inventory Stock Adjusted', [
        'direction' => 'in',
        'amount' => 99,
    ], $today->copy()->addHours(9));

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('movement', 7)
        ->where('movement.0.day', $today->copy()->subDays(6)->format('D'))
        ->where('movement.0.received', 0)
        ->where('movement.0.dispatched', 2)
        ->where('movement.4.day', $today->copy()->subDays(2)->format('D'))
        ->where('movement.4.received', 7)
        ->where('movement.4.dispatched', 0)
        ->where('movement.6.day', $today->format('D'))
        ->where('movement.6.received', 5)
        ->where('movement.6.dispatched', 3),
    );
});

test('dashboard returns zeros and empty collections for an empty team', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('summary.totalItems', 0)
        ->where('summary.totalUnits', 0)
        ->where('summary.lowStockCount', 0)
        ->where('summary.outOfStockCount', 0)
        ->where('summary.inventoryValue', 0)
        ->has('reorderItems', 0)
        ->has('activities', 0)
        ->has('movement', 7)
        ->where('movement.0.received', 0)
        ->where('movement.0.dispatched', 0)
        ->where('movement.6.received', 0)
        ->where('movement.6.dispatched', 0),
    );
});

function createDashboardInventoryItem(Team $team, string $sku, int $quantity, int $reorderPoint = 0, float $unitCost = 0): InventoryItem
{
    return $team->inventoryItems()->create([
        'sku' => $sku,
        'name' => "Item {$sku}",
        'quantity' => $quantity,
        'reorder_point' => $reorderPoint,
        'unit_cost' => $unitCost,
    ]);
}

/**
 * @param  array<string, mixed>  $properties
 */
function logDashboardInventoryActivity(Team $team, ?User $user, string $description, array $properties = [], ?CarbonInterface $createdAt = null): Activity
{
    $activity = activity('inventory')
        ->causedBy($user)
        ->withProperties([
            'team_id' => $team->id,
            'item_name' => 'Item',
            'sku' => 'SKU-1',
            ...$properties,
        ])
        ->log($description);

    if ($createdAt !== null) {
        $activity->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    return $activity;
}
