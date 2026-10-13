<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    /**
     * Determine whether the user can view any tags.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the tag.
     */
    public function view(User $user, Tag $tag): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create tags.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the tag.
     */
    public function update(User $user, Tag $tag): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the tag.
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can publish the tag.
     */
    public function publish(User $user, Tag $tag): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can unpublish the tag.
     */
    public function unpublish(User $user, Tag $tag): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can reorder tags.
     */
    public function reorder(User $user): bool
    {
        return $user->isAdministrator();
    }
}
