<?php

namespace App\Observers;

use App\Http\Controllers\Public\SitemapController;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Flush the cached sitemap when a change can alter the rendered document (§3.9).
 *
 * The sitemap is `Cache::remember`d for an hour on a constant key, so without
 * this flush a writing published after the first render stays missing for up to
 * that hour. One observer is registered against the seven content models and
 * `Tag`; it is deliberately cheap, because a `saved` write only forgets the
 * entry when an attribute that reaches the document actually changed.
 */
class SitemapCacheObserver
{
    /**
     * The attributes that change the document for every registered model.
     *
     * `status`/`published_at` decide whether a record is listed at all,
     * `deleted_at` removes it, and `slug`/`title` decide its URL and the tag
     * cloud's label. An unrelated attribute write — a summary, a body, a sort
     * order — changes none of them and must not flush.
     *
     * @var list<string>
     */
    private const WATCHED = ['status', 'published_at', 'slug', 'title', 'deleted_at'];

    /**
     * Forget the entry after a create or an update that touches the document.
     */
    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged($this->watched($model))) {
            $this->forget();
        }
    }

    /**
     * Forget the entry after a delete, soft or hard: the row leaves the sitemap.
     */
    public function deleted(Model $model): void
    {
        $this->forget();
    }

    /**
     * Forget the entry after a soft-deleted row comes back.
     */
    public function restored(Model $model): void
    {
        $this->forget();
    }

    /**
     * The watched attributes for one model.
     *
     * A writing's `updated_at` is the `<lastmod>` the sitemap publishes, so it is
     * watched there and nowhere else: for the other models it changes on every
     * write and would make the flush unconditional.
     *
     * @return list<string>
     */
    private function watched(Model $model): array
    {
        return $model instanceof Writing
            ? [...self::WATCHED, 'updated_at']
            : self::WATCHED;
    }

    /**
     * Drop the one cache entry the sitemap is stored under.
     */
    private function forget(): void
    {
        Cache::forget(SitemapController::CACHE_KEY);
    }
}
