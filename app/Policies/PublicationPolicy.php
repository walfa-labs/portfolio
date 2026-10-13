<?php

namespace App\Policies;

use App\Models\Publication;
use App\Models\User;

class PublicationPolicy
{
    /**
     * Determine whether the user can view any publications.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the publication.
     */
    public function view(User $user, Publication $publication): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create publications.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the publication.
     */
    public function update(User $user, Publication $publication): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the publication.
     */
    public function delete(User $user, Publication $publication): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can publish the publication.
     */
    public function publish(User $user, Publication $publication): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can unpublish the publication.
     */
    public function unpublish(User $user, Publication $publication): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can reorder publications.
     */
    public function reorder(User $user): bool
    {
        return $user->isAdministrator();
    }
}
