<?php

namespace App\Policies;

use App\Models\Certification;
use App\Models\User;

class CertificationPolicy
{
    /**
     * Determine whether the user can view any certifications.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the certification.
     */
    public function view(User $user, Certification $certification): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create certifications.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the certification.
     */
    public function update(User $user, Certification $certification): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the certification.
     */
    public function delete(User $user, Certification $certification): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can publish the certification.
     */
    public function publish(User $user, Certification $certification): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can unpublish the certification.
     */
    public function unpublish(User $user, Certification $certification): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can reorder certifications.
     */
    public function reorder(User $user): bool
    {
        return $user->isAdministrator();
    }
}
