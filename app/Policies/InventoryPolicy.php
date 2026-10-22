<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\InventoryItem;
use App\Models\Team;
use App\Models\User;

class InventoryPolicy
{
    /**
     * Determine whether the user can view any inventory items.
     */
    public function viewAny(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->teamRole($team) !== null;
    }

    /**
     * Determine whether the user can view the inventory item.
     */
    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->teamRole($inventoryItem->team) !== null;
    }

    /**
     * Determine whether the user can create inventory items.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user, $user->currentTeam);
    }

    /**
     * Determine whether the user can update the inventory item.
     */
    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->canManage($user, $inventoryItem->team);
    }

    /**
     * Determine whether the user can delete the inventory item.
     */
    public function delete(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->canManage($user, $inventoryItem->team);
    }

    /**
     * Determine whether the user can adjust the inventory item's stock.
     */
    public function adjust(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->canManage($user, $inventoryItem->team);
    }

    /**
     * Determine whether the user can update the inventory item's images.
     */
    public function updateImages(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->canManage($user, $inventoryItem->team);
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
