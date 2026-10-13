<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreWritingRequest;
use App\Http\Requests\Api\V1\UpdateWritingRequest;
use App\Http\Resources\Api\V1\WritingResource;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `writings` — Table A row 7 (§4.2).
 *
 * The richest resource, and the one with two carve-outs. `body` is published as
 * `body_markdown` (§4.4.2), so that — not `body` — is the selectable name; and
 * `series` is a read-only attribute object rather than a relationship, so it can
 * be selected but never included. `series_id` is what a write sets.
 *
 * The collection sorts by `-published_at` by default and also allows
 * `-updated_at`, which is the order the admin list wants (§4.8).
 */
class WritingController extends ApiController
{
    /**
     * The model the resource is built on.
     *
     * @return class-string<Writing>
     */
    protected function modelClass(): string
    {
        return Writing::class;
    }

    /**
     * The API Resource that shapes one record.
     *
     * @return class-string<WritingResource>
     */
    protected function resourceClass(): string
    {
        return WritingResource::class;
    }

    /**
     * The sort applied when the request carries none (§4.8).
     */
    protected function defaultSort(): string
    {
        return '-published_at';
    }

    /**
     * The sortable fields, without the direction prefix (§4.8).
     *
     * @return list<string>
     */
    protected function allowedSorts(): array
    {
        return ['published_at', 'title', 'updated_at'];
    }

    /**
     * The allowed filters (§4.8): the tag filters, a partial `q`, and `series`.
     *
     * `filter[series]` takes comma-separated series slugs — the vocabulary the
     * public site uses — and also accepts numeric ids, because the API's own
     * resource identifier for a series is its id (§4.4.2).
     */
    protected function allowedFilters(): array
    {
        return [
            ...$this->tagFilters(),
            'q' => $this->searchFilter(),
            'series' => function (Builder $query, mixed $value): void {
                $ids = [];
                $slugs = [];

                foreach ($this->listValues($value) as $item) {
                    if (ctype_digit($item)) {
                        $ids[] = (int) $item;
                    } else {
                        $slugs[] = $item;
                    }
                }

                if ($ids === [] && $slugs === []) {
                    return;
                }

                $query->where(function (Builder $query) use ($ids, $slugs): void {
                    if ($ids !== []) {
                        $query->orWhereIn('series_id', $ids);
                    }

                    if ($slugs !== []) {
                        $query->orWhereHas('series', fn (Builder $series): Builder => $series->whereIn('slug', $slugs));
                    }
                });
            },
        ];
    }

    /**
     * The attribute names `fields[writings]` may select (§4.9).
     *
     * `body_markdown` replaces the shared `body`, and `body_html` and
     * `reading_time_minutes` are derived, so only the reading time is published.
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return [
            'title',
            'slug',
            'summary',
            'excerpt',
            'body_markdown',
            'reading_time_minutes',
            'series',
            'published_at',
            'is_published',
            'is_placeholder',
            'created_at',
            'updated_at',
            'cover_url',
            'cover_variants',
            'cover_alt',
            'og_image_path',
            'tags',
        ];
    }

    /**
     * The attribute names a write may set (§4.5).
     *
     * `body_html` and `reading_time_minutes` are derived from `body` on save
     * (§2.6), so they are never writable; `series_id` is.
     *
     * @return list<string>
     */
    protected function writableAttributes(): array
    {
        return [
            ...$this->sharedWritableAttributes(),
            'excerpt',
            'series_id',
            'og_image_path',
        ];
    }

    /**
     * `POST /api/v1/writings` — create one record (§4.5).
     */
    public function store(StoreWritingRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/writings/{writing}` — full replacement (§4.5).
     */
    public function update(UpdateWritingRequest $request, Writing $writing): JsonResponse
    {
        return $this->amend($request, $writing);
    }

    /**
     * `PATCH /api/v1/writings/{writing}` — partial update (§4.5).
     */
    public function patch(UpdateWritingRequest $request, Writing $writing): JsonResponse
    {
        return $this->amend($request, $writing);
    }

    /**
     * `DELETE /api/v1/writings/{writing}` — soft delete (§4.5).
     */
    public function destroy(Writing $writing): Response
    {
        return $this->remove($writing);
    }
}
