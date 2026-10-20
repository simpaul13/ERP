<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('team members can log inventory actions', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(
        route('inventories.activity', ['current_team' => $user->personalTeam()->slug]),
        [
            'action' => 'Create',
            'item_name' => 'Shipping carton',
            'sku' => 'BX-2048',
            'image_names' => ['carton.png'],
        ],
    );

    $response->assertNoContent();
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'inventory',
        'description' => 'Inventory Create',
        'causer_id' => $user->id,
        'causer_type' => $user->getMorphClass(),
    ]);

    $activity = Activity::query()
        ->where('description', 'Inventory Create')
        ->firstOrFail();

    expect($activity->properties->get('item_name'))->toBe('Shipping carton')
        ->and($activity->properties->get('image_names'))->toBe(['carton.png']);
});

test('inventory activity only accepts supported actions', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(
        route('inventories.activity', ['current_team' => $user->personalTeam()->slug]),
        ['action' => 'Delete', 'item_name' => 'Shipping carton'],
    );

    $response->assertUnprocessable();
    $this->assertDatabaseCount('activity_log', 0);
});
