<?php

namespace App\Models\Concerns;

use App\Support\ContentSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * @phpstan-require-extends Model
 */
trait HasSlug
{
    /**
     * Derive the slug on save and guard against reuse when restoring.
     *
     * A new row always derives its slug from the title, so a client-supplied
     * slug can never win on create. An existing row keeps its slug unless it
     * has been cleared, which keeps the slug stable across title edits.
     */
    public static function bootHasSlug(): void
    {
        static::saving(function (self $model): void {
            if (! $model->exists || blank($model->slug)) {
                $model->slug = static::deriveSlug($model);
            }
        });

        static::registerModelEvent('restoring', function (self $model): void {
            if (static::slugIsTaken($model, $model->slug)) {
                abort(422, "The slug \"{$model->slug}\" is already taken.");
            }
        });
    }

    /**
     * Build a unique slug from the model's title.
     */
    protected static function deriveSlug(self $model): string
    {
        $base = ContentSlug::canonicalize((string) $model->title);

        if ($base === '') {
            $base = 'untitled';
        }

        $slug = $base;
        $suffix = 1;

        while (static::slugIsTaken($model, $slug)) {
            $slug = ContentSlug::withSuffix(ContentSlug::truncateForSuffix($base, ++$suffix), $suffix);
        }

        return $slug;
    }

    /**
     * Determine whether another row — including a soft-deleted one — already owns the slug.
     */
    protected static function slugIsTaken(self $model, string $slug): bool
    {
        if ($slug === '') {
            return false;
        }

        $query = static::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('slug', $slug);

        if ($model->getKey() !== null) {
            $query->whereKeyNot($model->getKey());
        }

        return $query->exists();
    }
}
