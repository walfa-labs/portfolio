<?php

namespace App\Support\Search;

use App\Enums\ContentType;
use App\Models\Tag;
use App\Support\ContentQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

/**
 * The single site-wide search definition (§3.8).
 *
 * Portable `LIKE` matching over the text columns of all seven content types plus
 * the joined tag names — no full-text index, because sqlite, MariaDB and any
 * future engine spell one differently. Ranking is exact tag > tag > title >
 * organisation > summary > body, tie-broken by `published_at` desc, which is the
 * order §3.8 fixes.
 *
 * The candidate set is merged in PHP rather than as a SQL `UNION`: the seven
 * tables share no common parent, the ordering expression differs per engine, and
 * the row count is small by construction (a single author's portfolio).
 *
 * The public page (§3.8) calls {@see self::search()} with `q`, `types` and a
 * page size; the API's `QUERY`/`GET /api/v1/search` (§4.1) additionally narrows
 * by tag slug and may override the relevance order. Both entry points share this
 * one definition, so a ranking change cannot reach one surface and miss the
 * other.
 */
class ContentSearcher
{
    /**
     * The shortest `q` that runs a search (§3.8: 2–100 characters).
     */
    public const MIN_QUERY_LENGTH = 2;

    /**
     * The longest `q` that runs a search.
     */
    public const MAX_QUERY_LENGTH = 100;

    /**
     * The default page size (§3.2/§3.8).
     */
    public const DEFAULT_PER_PAGE = 20;

    /**
     * The columns a caller may order the result set by (§4.8, search scope).
     *
     * Every content type shares these three — `title` and `updated_at` come from
     * the models, `published_at` from the shared `Publishable` trait — so one
     * order works for a result set that mixes types. The per-type date columns
     * (`end_date`, `issued_on`, `started_on`, `awarded_at`) are deliberately
     * absent: a writing has no `issued_on`, so ordering a mixed set by it would
     * be undefined.
     */
    public const SORTABLE = ['published_at', 'title', 'updated_at'];

    /**
     * Rank weights, highest first.
     */
    private const SCORE_EXACT_TAG = 4.0;

    private const SCORE_TAG = 3.5;

    private const SCORE_TITLE = 3.0;

    private const SCORE_ORGANISATION = 2.5;

    private const SCORE_SUMMARY = 2.0;

    private const SCORE_BODY = 1.0;

    /**
     * Run the search and return one page of ranked results.
     *
     * A `q` shorter than {@see self::MIN_QUERY_LENGTH} never reaches this method
     * on the public page — {@see App\Http\Controllers\Public\SearchController}
     * rejects it before calling. The guard here keeps the API path honest.
     *
     * @param  array<int, ContentType|string>  $types  Empty means every content type.
     * @param  array<int, string>  $tags  Tag slugs to narrow by; empty means every tag. The values are OR-ed, matching §4.8's `filter[tags]`.
     * @param  string|null  $sort  A {@see self::SORTABLE} field, optionally `-` prefixed for descending. Null, or any other field, keeps the relevance order.
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function search(
        string $q,
        array $types = [],
        int $perPage = self::DEFAULT_PER_PAGE,
        array $tags = [],
        ?string $sort = null,
    ): LengthAwarePaginator {
        $perPage = max(1, $perPage);
        $hits = self::ranked($q, $types, $tags, $sort);

        $page = max(1, Paginator::resolveCurrentPage());
        $total = count($hits);

        return new LengthAwarePaginator(
            array_slice($hits, ($page - 1) * $perPage, $perPage),
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query()],
        );
    }

    /**
     * Every hit for the query, in order, before pagination.
     *
     * The API needs the whole set: `meta.counts_by_type` counts hits the caller's
     * page does not contain (§4.4.3). The public page keeps using
     * {@see self::search()}, which slices this list.
     *
     * @param  array<int, ContentType|string>  $types
     * @param  array<int, string>  $tags
     * @return list<array<string, mixed>>
     */
    public static function ranked(string $q, array $types = [], array $tags = [], ?string $sort = null): array
    {
        $needle = self::needle($q);
        $ranked = [];

        if ($needle !== '') {
            foreach (self::types($types) as $type) {
                foreach (self::query($type, $needle, $tags)->get() as $model) {
                    $score = self::score($type, $model, $needle);

                    if ($score > 0.0) {
                        $ranked[] = [
                            'score' => $score,
                            'published_at' => self::timestamp($model, 'published_at'),
                            'updated_at' => self::timestamp($model, 'updated_at'),
                            'title' => (string) $model->getAttribute('title'),
                            'id' => (int) $model->getKey(),
                            'item' => self::result($type, $model, $score),
                        ];
                    }
                }
            }
        }

        self::order($ranked, $sort);

        return array_map(static fn (array $row): array => $row['item'], $ranked);
    }

    /**
     * The hit count per content type, keyed by the type's canonical singular.
     *
     * Feeds the API document's `meta.counts_by_type` (§4.4.3), which counts the
     * whole result set rather than the current page — the example shows a total
     * of two across two types. Types with no hits are absent rather than zero.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return array<string, int>
     */
    public static function countsByType(array $hits): array
    {
        $counts = [];

        foreach ($hits as $hit) {
            $type = $hit['type'] ?? null;

            if (is_string($type) && $type !== '') {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Order the ranked rows: by relevance, or by the caller's chosen field.
     *
     * Relevance (score desc, then `published_at` desc, then id) is the §3.8 order
     * and the default. An explicit `sort` replaces it — a caller who asks for
     * `-published_at` wants a date order, not a relevance order that happens to
     * be date-tied — and relevance then breaks ties.
     *
     * An unrecognised field falls back to the relevance order instead of
     * erroring: the API allow-lists `sort` and answers 400 before reaching here,
     * so an unknown value means a non-API caller, and guessing is worse than the
     * documented default.
     *
     * @param  list<array<string, mixed>>  $ranked
     */
    private static function order(array &$ranked, ?string $sort): void
    {
        $field = $sort === null ? null : ltrim($sort, '-');

        if ($field === null || ! in_array($field, self::SORTABLE, true)) {
            $field = null;
        }

        $descending = $sort !== null && str_starts_with($sort, '-');

        usort($ranked, static function (array $a, array $b) use ($field, $descending): int {
            if ($field === null) {
                $comparison = $b['score'] <=> $a['score'];
            } else {
                $comparison = $field === 'title'
                    ? strcasecmp((string) $a['title'], (string) $b['title'])
                    : $a[$field] <=> $b[$field];

                if ($descending) {
                    $comparison = -$comparison;
                }
            }

            return $comparison
                ?: ($field === null ? 0 : $b['score'] <=> $a['score'])
                ?: $b['published_at'] <=> $a['published_at']
                ?: $b['id'] <=> $a['id'];
        });
    }

    /**
     * Reduce a raw `q` to the lower-cased needle the ranking compares against.
     *
     * `%` and `_` are stripped rather than escaped: `LIKE ... ESCAPE` is spelled
     * differently on sqlite and MariaDB, and a wildcard is never useful in a
     * portfolio search box.
     */
    private static function needle(string $q): string
    {
        return mb_strtolower(str_replace(['%', '_'], '', trim($q)));
    }

    /**
     * Resolve the requested types, keeping the canonical order and ignoring junk.
     *
     * Both the enum value (`writing`) and the API's plural-kebab segment
     * (`writings`) are accepted, so the API can hand its own vocabulary straight
     * through. An empty or wholly unrecognised selection searches everything.
     *
     * @param  array<int, ContentType|string>  $types
     * @return list<ContentType>
     */
    private static function types(array $types): array
    {
        $resolved = [];

        foreach ($types as $candidate) {
            $matched = $candidate instanceof ContentType
                ? $candidate
                : self::matchType((string) $candidate);

            if ($matched !== null && ! in_array($matched, $resolved, true)) {
                $resolved[] = $matched;
            }
        }

        return $resolved === [] ? ContentType::cases() : $resolved;
    }

    private static function matchType(string $candidate): ?ContentType
    {
        $candidate = trim($candidate);

        foreach (ContentType::cases() as $type) {
            if ($candidate === $type->value || $candidate === $type->routeSegment()) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Build the candidate query for one content type.
     *
     * The tag filter is a separate top-level `whereHas` so it ANDs with the text
     * match instead of joining the `orWhere` group: `tags=laravel` narrows a
     * search, it does not widen it.
     *
     * @param  array<int, string>  $tags
     * @return Builder<Model>
     */
    private static function query(ContentType $type, string $needle, array $tags = []): Builder
    {
        $like = "%{$needle}%";

        $query = ContentQuery::published($type->modelClass())->with('tags');

        if ($tags !== []) {
            $query->whereHas('tags', fn (Builder $tag): Builder => $tag->whereIn('slug', $tags));
        }

        return $query->where(function (Builder $query) use ($type, $like): void {
            foreach (self::textColumns($type) as $column) {
                $query->orWhere($column, 'like', $like);
            }

            $query->orWhereHas('tags', fn (Builder $tags) => $tags->where('name', 'like', $like));
        });
    }

    /**
     * Rank a matched record against the needle.
     *
     * The query has already established that *something* matches; this decides
     * which field, because an exact tag hit must outrank a body hit (§3.8).
     */
    private static function score(ContentType $type, Model $model, string $needle): float
    {
        /** @var Collection<int, Tag> $tags */
        $tags = $model->getRelation('tags');

        if ($tags->contains(fn (Tag $tag): bool => mb_strtolower($tag->name) === $needle)) {
            return self::SCORE_EXACT_TAG;
        }

        if ($tags->contains(fn (Tag $tag): bool => str_contains(mb_strtolower($tag->name), $needle))) {
            return self::SCORE_TAG;
        }

        if (self::attributeContains($model, 'title', $needle)) {
            return self::SCORE_TITLE;
        }

        foreach (self::organisationColumns($type) as $column) {
            if (self::attributeContains($model, $column, $needle)) {
                return self::SCORE_ORGANISATION;
            }
        }

        foreach (self::summaryColumns($type) as $column) {
            if (self::attributeContains($model, $column, $needle)) {
                return self::SCORE_SUMMARY;
            }
        }

        return self::SCORE_BODY;
    }

    private static function attributeContains(Model $model, string $column, string $needle): bool
    {
        $value = $model->getAttribute($column);

        return is_string($value) && $value !== '' && str_contains(mb_strtolower($value), $needle);
    }

    /**
     * A record's date column as a Unix timestamp, for the ordering.
     */
    private static function timestamp(Model $model, string $column): int
    {
        $value = $model->getAttribute($column);

        return $value instanceof DateTimeInterface ? $value->getTimestamp() : 0;
    }

    /**
     * The columns the `LIKE` runs against, per content type.
     *
     * @return list<string>
     */
    private static function textColumns(ContentType $type): array
    {
        return match ($type) {
            ContentType::WorkExperience => ['title', 'summary', 'employer'],
            ContentType::Education => ['title', 'summary', 'institution', 'field_of_study'],
            ContentType::Certification => ['title', 'summary', 'issuer'],
            ContentType::Publication => ['title', 'summary', 'venue', 'authors', 'doi'],
            ContentType::Project => ['title', 'summary'],
            ContentType::Award => ['title', 'summary', 'awarding_body'],
            ContentType::Writing => ['title', 'summary', 'excerpt', 'body'],
        };
    }

    /**
     * The "who made it" columns — the middle rank between title and summary.
     *
     * @return list<string>
     */
    private static function organisationColumns(ContentType $type): array
    {
        return match ($type) {
            ContentType::WorkExperience => ['employer'],
            ContentType::Education => ['institution', 'field_of_study'],
            ContentType::Certification => ['issuer'],
            ContentType::Publication => ['venue', 'authors', 'doi'],
            ContentType::Award => ['awarding_body'],
            ContentType::Project, ContentType::Writing => [],
        };
    }

    /**
     * The descriptive columns, ranked below the title.
     *
     * @return list<string>
     */
    private static function summaryColumns(ContentType $type): array
    {
        return match ($type) {
            ContentType::Writing => ['summary', 'excerpt'],
            default => ['summary'],
        };
    }

    /**
     * Map a matched record to one search result.
     *
     * `path` is a relative internal URL — the shape §4.4.3 makes authoritative
     * for the search document, and the value `ContentCard` needs for `href`.
     *
     * @return array<string, mixed>
     */
    private static function result(ContentType $type, Model $model, float $score): array
    {
        /** @var Collection<int, Tag> $tags */
        $tags = $model->getRelation('tags');
        $publishedAt = $model->getAttribute('published_at');

        return [
            'type' => $type->value,
            'id' => (int) $model->getKey(),
            'title' => (string) $model->getAttribute('title'),
            'excerpt' => self::excerpt($type, $model),
            'path' => self::path($type, $model),
            'score' => round($score / self::SCORE_EXACT_TAG, 2),
            'published_at' => $publishedAt instanceof DateTimeInterface ? $publishedAt->format(DateTimeInterface::ATOM) : null,
            'is_placeholder' => (bool) $model->getAttribute('is_placeholder'),
            'tags' => array_values($tags
                ->map(fn (Tag $tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
                ->all()),
        ];
    }

    private static function excerpt(ContentType $type, Model $model): string
    {
        $columns = $type === ContentType::Writing ? ['excerpt', 'summary'] : ['summary'];

        foreach ($columns as $column) {
            $value = $model->getAttribute($column);

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(strip_tags($value), 200);
            }
        }

        return '';
    }

    /**
     * The canonical public URL for a hit.
     *
     * Only `writing` has a detail page (§3.6); every other type's card links to
     * the list page that renders it.
     */
    private static function path(ContentType $type, Model $model): string
    {
        return $type === ContentType::Writing
            ? route('writing.show', $model, false)
            : route(self::listRoute($type), absolute: false);
    }

    private static function listRoute(ContentType $type): string
    {
        return match ($type) {
            ContentType::WorkExperience => 'experience.index',
            ContentType::Education => 'education.index',
            ContentType::Certification => 'certifications.index',
            ContentType::Publication => 'publications.index',
            ContentType::Project => 'projects.index',
            ContentType::Award => 'awards.index',
            ContentType::Writing => 'writing.index',
        };
    }
}
