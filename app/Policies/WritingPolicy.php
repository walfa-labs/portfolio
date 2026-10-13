<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Writing;

class WritingPolicy
{
    /**
     * Determine whether the user can view any writings.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the writing.
     */
    public function view(User $user, Writing $writing): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create writings.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the writing.
     */
    public function update(User $user, Writing $writing): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the writing.
     */
    public function delete(User $user, Writing $writing): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can publish the writing.
     */
    public function publish(User $user, Writing $writing): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can unpublish the writing.
     */
    public function unpublish(User $user, Writing $writing): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can reorder writings.
     */
    public function reorder(User $user): bool
    {
        return $user->isAdministrator();
    }
}
