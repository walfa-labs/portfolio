<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sweep the media table for rows nothing will ever finish (§9.8).
 *
 * Three independent rules, each idempotent by construction — a second run
 * finds nothing left to do:
 *
 * 1. `pending`/`processing` rows older than 24 h are abandoned uploads; the
 *    row and its original object go.
 * 2. `failed` rows older than 7 days are given up on.
 * 3. a row whose owning content no longer exists is hard-deleted, as
 *    belt-and-braces behind the `forceDeleting` hook `HasMedia` registers.
 *
 * Rows are deleted one at a time so `Media::deleting()` runs and the original
 * and every variant object leave the disk, honouring the shared-object rule.
 * A soft-deleted content row still owns its media (§15.20), so rule 3 looks
 * past the soft-delete scope.
 */
#[Signature('media:prune')]
#[Description('Delete stale, failed and orphaned media rows together with their stored objects')]
class PruneMedia extends Command
{
    /**
     * How old an unfinished upload must be before it is considered abandoned.
     */
    protected const int STALE_HOURS = 24;

    /**
     * How old a failed row must be before it is given up on.
     */
    protected const int FAILED_DAYS = 7;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        [$stale, $abandoned] = $this->pruneStaleUploads();

        $failed = $this->deleteRows(
            Media::query()
                ->where('status', 'failed')
                ->where('created_at', '<', now()->subDays(self::FAILED_DAYS)),
        );

        $orphaned = $this->deleteOrphans();

        Log::info('media:prune completed.', [
            'stale' => $stale,
            'abandoned' => $abandoned,
            'failed' => $failed,
            'orphaned' => $orphaned,
        ]);

        $this->info("Pruned {$stale} stale, marked {$abandoned} abandoned, deleted {$failed} failed and {$orphaned} orphaned media row(s).");

        return self::SUCCESS;
    }

    /**
     * Delete abandoned uploads, but keep any whose original is already gone.
     *
     * A stale row with no original on the disk may be racing the job — the
     * job's `[10, 60, 300]` backoff can outlast the 24 h window — so it is
     * marked `failed` and kept. Deleting it would drop the only record that an
     * upload was ever attempted, and the row is what `media:prune`'s own
     * seven-day window is for.
     *
     * @return array{0: int, 1: int} The deleted and the marked-failed counts.
     */
    protected function pruneStaleUploads(): array
    {
        $deleted = 0;
        $abandoned = 0;

        $rows = Media::query()
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', now()->subHours(self::STALE_HOURS))
            ->get();

        foreach ($rows as $media) {
            if (! $this->originalExists($media)) {
                $media->update(['status' => 'failed']);
                $abandoned++;

                continue;
            }

            $media->delete();
            $deleted++;
        }

        return [$deleted, $abandoned];
    }

    /**
     * Determine whether the row's original object is still on its disk.
     *
     * An unreachable disk counts as "gone", which keeps the row rather than
     * deleting objects that may still exist.
     */
    protected function originalExists(Media $media): bool
    {
        try {
            return Storage::disk($media->disk)->exists($media->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Delete every row the query selects.
     *
     * The rows go through `delete()` individually, never a mass delete, so each
     * one fires its `deleting` hook and its objects are removed with it.
     *
     * @param  Builder<Media>  $query
     */
    protected function deleteRows(Builder $query): int
    {
        $count = 0;

        foreach ($query->get() as $media) {
            $media->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Delete rows whose owning content no longer exists.
     */
    protected function deleteOrphans(): int
    {
        $count = 0;

        Media::query()->chunkById(200, function ($rows) use (&$count): void {
            foreach ($rows as $media) {
                if ($this->mediableExists($media)) {
                    continue;
                }

                $media->delete();
                $count++;
            }
        });

        return $count;
    }

    /**
     * Determine whether the row's owning content still exists.
     *
     * The morph alias is resolved through the registered morph map, so a row
     * naming an unregistered type is an orphan rather than a crash. The
     * soft-delete scope is dropped when the model uses it: a soft-deleted
     * project keeps its media until it is force-deleted (§15.20).
     */
    protected function mediableExists(Media $media): bool
    {
        $class = Relation::getMorphedModel($media->mediable_type) ?? $media->mediable_type;

        if (! is_subclass_of($class, Model::class)) {
            return false;
        }

        return $class::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereKey($media->mediable_id)
            ->exists();
    }
}
