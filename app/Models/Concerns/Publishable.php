<?php

namespace App\Models\Concerns;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @phpstan-require-extends Model
 */
trait Publishable
{
    use SoftDeletes;

    /**
     * Register the force-delete listener that detaches the model's tags.
     */
    public static function bootPublishable(): void
    {
        static::forceDeleting(function (self $model): void {
            $model->tags()->detach();
        });
    }

    /**
     * Limit the query to records that are published and past their publication time.
     *
     * @param  Builder<static>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published)
            ->where('published_at', '<=', now());
    }

    /**
     * Publish the record, stamping the publication time only when it is not already set.
     */
    public function publish(): void
    {
        $this->status = ContentStatus::Published;

        if ($this->published_at === null) {
            $this->published_at = now();
        }

        $this->save();
    }

    /**
     * Return the record to draft and clear its publication time.
     */
    public function unpublish(): void
    {
        $this->status = ContentStatus::Draft;
        $this->published_at = null;

        $this->save();
    }
}
