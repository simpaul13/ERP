<?php

namespace App\Policies;

use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\SalesOrder;
use App\Models\User;

class SalesOrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null && $user->teamRole($user->currentTeam) !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SalesOrder $salesOrder): bool
    {
        return $user->teamRole($salesOrder->team) !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SalesOrder $salesOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SalesOrder $salesOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SalesOrder $salesOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SalesOrder $salesOrder): bool
    {
        return false;
    }

    public function manage(User $user, SalesOrder $salesOrder): bool
    {
        return $user->teamRole($salesOrder->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    public function reserve(User $user, SalesOrder $salesOrder): bool
    {
        return $this->manage($user, $salesOrder);
    }

    public function fulfill(User $user, SalesOrder $salesOrder): bool
    {
        return $this->manage($user, $salesOrder);
    }

    public function returnGoods(User $user, SalesOrder $salesOrder): bool
    {
        return $this->manage($user, $salesOrder);
    }

    public function refund(User $user, SalesOrder $salesOrder): bool
    {
        return $this->manage($user, $salesOrder);
    }

    public function cancel(User $user, SalesOrder $salesOrder): bool
    {
        return $this->manage($user, $salesOrder)
            || ($this->view($user, $salesOrder)
                && $user->id === $salesOrder->created_by
                && in_array($salesOrder->status, [SalesOrderStatus::Draft, SalesOrderStatus::Cancelled], true));
    }
}
