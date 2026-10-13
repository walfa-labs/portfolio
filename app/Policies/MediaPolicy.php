<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

/**
 * The media authorisation seam (§9.10).
 *
 * Exactly the three abilities the admin media surface needs — `create`,
 * `update`, `delete` — each returning the single authorisation check the whole
 * admin surface uses (§5.7). §2.6 describes nine policies with eight
 * abilities each; that is over-specified for media, which has no view, publish
 * or reorder action (§9.10).
 */
class MediaPolicy
{
    /**
     * Determine whether the user can upload media.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can regenerate a media row's variants.
     */
    public function update(User $user, Media $media): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete a media row.
     */
    public function delete(User $user, Media $media): bool
    {
        return $user->isAdministrator();
    }
}
