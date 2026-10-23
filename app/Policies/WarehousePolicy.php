<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy
{
    /**
     * Determine whether the user can view any warehouses.
     */
    public function viewAny(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->teamRole($team) !== null;
    }

    /**
     * Determine whether the user can view the warehouse.
     */
    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->teamRole($warehouse->team) !== null;
    }

    /**
     * Determine whether the user can create warehouses.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user, $user->currentTeam);
    }

    /**
     * Determine whether the user can update the warehouse.
     */
    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->canManage($user, $warehouse->team);
    }

    /**
     * Determine whether the user can delete the warehouse.
     */
    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $this->canManage($user, $warehouse->team);
    }

    /**
     * Determine whether the user has at least the admin role on the team.
     */
    private function canManage(User $user, ?Team $team): bool
    {
        return $team !== null
            && ($user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false);
    }
}
