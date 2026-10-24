<?php

use App\Enums\TeamRole;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('team members can view suppliers and admins can create update and archive them', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $indexUrl = route('suppliers.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('suppliers.store', ['current_team' => $team->slug]), [
            'name' => 'Northwind Parts',
            'contact_name' => 'Jordan Lee',
            'email' => 'jordan@example.test',
            'phone' => '+1 555 0100',
            'billing_address' => '12 Dock Road',
        ])
        ->assertRedirect($indexUrl);

    $supplier = $team->suppliers()->firstOrFail();

    expect($supplier->name)->toBe('Northwind Parts')
        ->and($supplier->contact_name)->toBe('Jordan Lee')
        ->and($supplier->is_active)->toBeTrue();

    $this->actingAs($user)
        ->get($indexUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('suppliers/Index')
            ->where('canManage', true)
            ->has('suppliers', 1)
            ->where('suppliers.0.name', 'Northwind Parts')
            ->where('suppliers.0.email', 'jordan@example.test'));

    $this->actingAs($user)
        ->from($indexUrl)
        ->patch(route('suppliers.update', [
            'current_team' => $team->slug,
            'supplier' => $supplier->id,
        ]), [
            'name' => 'Northwind Industrial',
            'contact_name' => 'Jordan Lee',
            'email' => 'jordan@example.test',
            'phone' => '+1 555 0100',
            'billing_address' => '14 Dock Road',
            'is_active' => false,
        ])
        ->assertRedirect($indexUrl);

    expect($supplier->fresh()->name)->toBe('Northwind Industrial')
        ->and($supplier->fresh()->is_active)->toBeFalse();

    $this->actingAs($user)
        ->delete(route('suppliers.destroy', [
            'current_team' => $team->slug,
            'supplier' => $supplier->id,
        ]))
        ->assertRedirect($indexUrl);

    $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
});

test('supplier creation validates required name and contact email', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();
    $indexUrl = route('suppliers.index', ['current_team' => $team->slug]);

    $this->actingAs($user)
        ->from($indexUrl)
        ->post(route('suppliers.store', ['current_team' => $team->slug]), [
            'name' => ' ',
            'email' => 'not-an-email',
        ])
        ->assertRedirect($indexUrl)
        ->assertSessionHasErrors(['name', 'email']);

    expect($team->suppliers()->count())->toBe(0);
});

test('team members can view suppliers but cannot create or change them', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $supplier = $team->suppliers()->create(['name' => 'Allowed Supplier']);
    $indexUrl = route('suppliers.index', ['current_team' => $team->slug]);

    $this->actingAs($member)
        ->get($indexUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->has('suppliers', 1));

    $this->actingAs($member)
        ->post(route('suppliers.store', ['current_team' => $team->slug]), ['name' => 'Forbidden'])
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('suppliers.update', [
            'current_team' => $team->slug,
            'supplier' => $supplier->id,
        ]), ['name' => 'Changed'])
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('suppliers.destroy', [
            'current_team' => $team->slug,
            'supplier' => $supplier->id,
        ]))
        ->assertForbidden();
});

test('a team cannot access another teams suppliers', function () {
    $user = User::factory()->create();
    $otherTeam = User::factory()->create()->personalTeam();
    $supplier = $otherTeam->suppliers()->create(['name' => 'Private Supplier']);
    $team = $user->personalTeam();

    $this->actingAs($user)
        ->patch(route('suppliers.update', [
            'current_team' => $team->slug,
            'supplier' => $supplier->id,
        ]), ['name' => 'Hijacked Supplier'])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('suppliers.destroy', [
            'current_team' => $team->slug,
            'supplier' => $supplier->id,
        ]))
        ->assertNotFound();

    expect($supplier->fresh()->name)->toBe('Private Supplier')
        ->and($team->suppliers()->exists())->toBeFalse();
});

test('supplier records are scoped to their owning team', function () {
    $team = Team::factory()->create();
    $supplier = Supplier::factory()->for($team)->create();

    expect($team->suppliers()->whereKey($supplier->id)->exists())->toBeTrue()
        ->and($supplier->team->is($team))->toBeTrue();
});
