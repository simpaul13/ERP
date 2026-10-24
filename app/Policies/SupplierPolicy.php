<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;

class SupplierPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->teamRole($team) !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Supplier $supplier): bool
    {
        return $user->teamRole($supplier->team) !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user, $user->currentTeam);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Supplier $supplier): bool
    {
        return $this->canManage($user, $supplier->team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->canManage($user, $supplier->team);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Supplier $supplier): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
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
