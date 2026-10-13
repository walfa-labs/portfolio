<?php

namespace App\Support;

use App\Enums\ContentType;
use App\Models\Concerns\Publishable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one query helper every public list controller shares.
 *
 * Only published content is public (§3.1 item 4): a draft or an unknown slug is
 * a 404, never a 403. Keeping that rule — and the pagination contract that goes
 * with it — in one place stops six list controllers from drifting apart (§3.6).
 *
 * Ordering is deliberately *not* here: it differs per list (`/experience` is
 * reverse-chronological with open-ended roles first, `/tags/{slug}` is grouped
 * by content type), so each controller owns its own `orderBy` chain.
 */
class ContentQuery
{
    /**
     * Start a query limited to published records.
     *
     * The model must use the {@see Publishable} trait, which supplies the
     * `published()` scope. The scope is invoked through `scopes()` rather than
     * as `->published()` so the call stays typed for a model class the analyser
     * only knows as `Model`; it resolves to the same `scopePublished()`.
     *
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    public static function published(string $modelClass): Builder
    {
        return $modelClass::query()->scopes(['published']);
    }

    /**
     * Paginate a list query, keeping the current query string on the page links.
     *
     * Paginated URLs — not infinite scroll — are the contract (§3.1 item 6), so
     * `?page=N` is appended to whatever filter the request already carried
     * (`/search?q=…&type=…&page=2` keeps `q` and `type`).
     *
     * @param  Builder<Model>  $query
     * @return LengthAwarePaginator<int, Model>
     */
    public static function paginate(Builder $query, int $perPage): LengthAwarePaginator
    {
        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * The published item count per tag, aggregated by content type.
     *
     * The pivot points at seven different tables, so each type is counted with
     * one grouped query and the totals are merged in PHP — a single `COUNT(*)`
     * would have to `UNION` them with engine-specific SQL. This is the one
     * aggregation behind the home skills cloud (§3.5 section 4) and the
     * `/tags` counts (§3.7), so the two surfaces cannot drift apart again.
     *
     * @return array<int, int>
     */
    public static function publishedTagCounts(): array
    {
        $counts = [];

        foreach (ContentType::cases() as $type) {
            $model = $type->modelClass();
            $table = (new $model)->getTable();

            $rows = self::published($type->modelClass())
                ->join('taggables', 'taggables.taggable_id', '=', $table.'.id')
                ->where('taggables.taggable_type', $type->value)
                ->groupBy('taggables.tag_id')
                ->selectRaw('taggables.tag_id as tag_id, COUNT(*) as aggregate')
                ->get();

            foreach ($rows as $row) {
                $tagId = (int) $row->getAttribute('tag_id');

                $counts[$tagId] = ($counts[$tagId] ?? 0) + (int) $row->getAttribute('aggregate');
            }
        }

        return $counts;
    }
}
