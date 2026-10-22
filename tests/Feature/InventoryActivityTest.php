<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('inventory mutations are logged server side with team context', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $item = $team->inventoryItems()->create([
        'sku' => 'BX-2048',
        'name' => 'Shipping carton',
        'quantity' => 5,
        'reorder_point' => 2,
        'unit_cost' => 1,
    ]);
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->patch(
            route('inventories.update', [
                'current_team' => $team->slug,
                'inventoryItem' => $item->id,
            ]),
            [
                'name' => 'Insulated carton',
                'sku' => 'BX-2048',
                'reorder_point' => 2,
                'unit_cost' => 1,
            ],
        )
        ->assertRedirect($indexUrl);

    $activity = Activity::query()
        ->where('log_name', 'inventory')
        ->where('description', 'Inventory Updated')
        ->firstOrFail();

    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->causer_type)->toBe($user->getMorphClass())
        ->and($activity->getProperty('team_id'))->toBe($team->id)
        ->and($activity->getProperty('item_name'))->toBe('Insulated carton')
        ->and($activity->getProperty('sku'))->toBe('BX-2048')
        ->and($activity->getProperty('changes'))->toHaveKey('name');
});

test('inventory index only exposes activities for the current team', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->personalTeam();
    $indexUrl = route('inventories.index', ['current_team' => $team->slug]);

    $this->actingAs($user)->from($indexUrl)->post(
        route('inventories.store', ['current_team' => $team->slug]),
        [
            'name' => 'Shipping carton',
            'sku' => 'BX-2048',
            'quantity' => 2,
            'reorder_point' => 1,
            'unit_cost' => 1,
        ],
    );

    $this->actingAs($otherUser)
        ->from(route('inventories.index', ['current_team' => $otherTeam->slug]))
        ->post(
            route('inventories.store', ['current_team' => $otherTeam->slug]),
            [
                'name' => 'Other carton',
                'sku' => 'BX-4096',
                'quantity' => 2,
                'reorder_point' => 1,
                'unit_cost' => 1,
            ],
        );

    $this->actingAs($user)
        ->get($indexUrl)
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities', 1)
            ->where('activities.0.description', 'Inventory Created')
            ->where('activities.0.itemName', 'Shipping carton')
            ->where('activities.0.userName', $user->name),
        );
});

test('inventory activities without a causer are attributed to the system', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();

    Activity::query()->create([
        'log_name' => 'inventory',
        'description' => 'Inventory Created',
        'properties' => [
            'team_id' => $team->id,
            'item_name' => 'Legacy carton',
            'sku' => 'BX-1024',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('inventories.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities', 1)
            ->where('activities.0.itemName', 'Legacy carton')
            ->where('activities.0.userName', 'System'),
        );
});

test('the legacy inventory activity endpoint has been removed', function () {
    expect(Route::has('inventories.activity'))->toBeFalse();

    $user = User::factory()->create();
    $team = $user->personalTeam();

    $response = $this->actingAs($user)->postJson(
        '/'.$team->slug.'/inventories/activity',
        [
            'action' => 'Create',
            'item_name' => 'Shipping carton',
        ],
    );

    expect($response->status())->toBeIn([404, 405]);
    $this->assertDatabaseCount('activity_log', 0);
});
