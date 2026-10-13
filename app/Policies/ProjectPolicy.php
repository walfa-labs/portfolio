<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any projects.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create projects.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can publish the project.
     */
    public function publish(User $user, Project $project): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can unpublish the project.
     */
    public function unpublish(User $user, Project $project): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can reorder projects.
     */
    public function reorder(User $user): bool
    {
        return $user->isAdministrator();
    }
}
