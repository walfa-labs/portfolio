<?php

namespace App\Policies;

use App\Models\Award;
use App\Models\User;

class AwardPolicy
{
    /**
     * Determine whether the user can view any awards.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the award.
     */
    public function view(User $user, Award $award): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create awards.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the award.
     */
    public function update(User $user, Award $award): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the award.
     */
    public function delete(User $user, Award $award): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can publish the award.
     */
    public function publish(User $user, Award $award): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can unpublish the award.
     */
    public function unpublish(User $user, Award $award): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can reorder awards.
     */
    public function reorder(User $user): bool
    {
        return $user->isAdministrator();
    }
}
