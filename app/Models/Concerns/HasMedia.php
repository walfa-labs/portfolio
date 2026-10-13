<?php

namespace App\Models\Concerns;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @phpstan-require-extends Model
 */
trait HasMedia
{
    /**
     * Get the media attached to the model.
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * Delete every media row attached to the model.
     *
     * Rows are deleted individually so each one fires its own deleting event,
     * which removes the underlying stored objects.
     */
    public function deleteMedia(): void
    {
        $this->media()->get()->each(function (Media $media): void {
            $media->delete();
        });
    }

    /**
     * Register the force-delete listener that clears the model's media rows.
     *
     * Registered through the model event dispatcher rather than a trait method so
     * this trait never collides with the `forceDeleting` hook owned by
     * {@see Publishable}, and so it also boots on models without soft deletes.
     */
    public static function bootHasMedia(): void
    {
        static::registerModelEvent('forceDeleting', function (self $model): void {
            $model->deleteMedia();
        });
    }
}
