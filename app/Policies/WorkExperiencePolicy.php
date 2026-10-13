<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkExperience;

class WorkExperiencePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WorkExperience $workExperience): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkExperience $workExperience): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkExperience $workExperience): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can publish the model.
     */
    public function publish(User $user, WorkExperience $workExperience): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can unpublish the model.
     */
    public function unpublish(User $user, WorkExperience $workExperience): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can reorder the models.
     */
    public function reorder(User $user): bool
    {
        return $user->isAdministrator();
    }
}
