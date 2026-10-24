<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Enums\TeamRole;
use App\Models\PurchaseOrder;
use App\Models\Team;
use App\Models\User;

class PurchaseOrderPolicy
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
    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->teamRole($purchaseOrder->team) !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->currentTeam !== null && $user->teamRole($user->currentTeam) !== null;
    }

    public function approveAny(User $user): bool
    {
        return $user->currentTeam !== null && $this->isAdmin($user, $user->currentTeam);
    }

    public function receiveAny(User $user): bool
    {
        return $user->currentTeam !== null && $this->isAdmin($user, $user->currentTeam);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === PurchaseOrderStatus::Draft
            && ($user->id === $purchaseOrder->created_by || $this->isAdmin($user, $purchaseOrder->team));
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === PurchaseOrderStatus::Draft
            && ($user->id === $purchaseOrder->created_by || $this->isAdmin($user, $purchaseOrder->team));
    }

    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === PurchaseOrderStatus::Submitted
            && $this->isAdmin($user, $purchaseOrder->team);
    }

    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return in_array($purchaseOrder->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true)
            && $this->isAdmin($user, $purchaseOrder->team);
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return in_array($purchaseOrder->status, [
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::Submitted,
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::PartiallyReceived,
        ], true) && (
            $this->isAdmin($user, $purchaseOrder->team)
            || ($purchaseOrder->status === PurchaseOrderStatus::Draft && $user->id === $purchaseOrder->created_by)
        );
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return false;
    }

    private function isAdmin(User $user, Team $team): bool
    {
        return $user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false;
    }
}
