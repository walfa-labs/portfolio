<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreTagRequest;
use App\Http\Requests\Api\V1\UpdateTagRequest;
use App\Http\Resources\Api\V1\TagResource;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `tags` — Table A row 8 (§4.2).
 *
 * A full six-method resource with no exceptions (§15.8), but its own table:
 * `name`, `description` and `is_placeholder` are the writable members, and the
 * content columns (`status`, `published_at`, `meta_*`, `sort_order`) do not
 * exist, so supplying one is a 422 rather than a silent drop (§2.5).
 *
 * A tag is not publishable, so the read scope is not `published()` — the
 * `TagController` of the public site lists every tag too (§3.7) — and a tag has
 * no `tags` relationship of its own, so `filter[tag]` matches the tag's own slug
 * and `include` is not declared at all: a tag has nothing to embed, so an
 * `include` request is the 400 §4.9 gives an endpoint that declares none.
 */
class TagController extends ApiController
{
    /**
     * The model the resource is built on.
     *
     * @return class-string<Tag>
     */
    protected function modelClass(): string
    {
        return Tag::class;
    }

    /**
     * The API Resource that shapes one record.
     *
     * @return class-string<TagResource>
     */
    protected function resourceClass(): string
    {
        return TagResource::class;
    }

    /**
     * The sort applied when the request carries none (§4.8).
     */
    protected function defaultSort(): string
    {
        return 'name';
    }

    /**
     * The sortable fields, without the direction prefix (§4.8).
     *
     * A tag has no `title` and no date column, so `name` is the whole list.
     *
     * @return list<string>
     */
    protected function allowedSorts(): array
    {
        return ['name'];
    }

    /**
     * The allowed filters (§4.8).
     *
     * `filter[tag]` names the tag's own slug rather than a related one: a tag is
     * not tagged with anything, and the filter still has to mean something on a
     * resource the contract gives the same filter set as every other. `q`
     * matches the name and the description.
     */
    protected function allowedFilters(): array
    {
        $bySlug = function (Builder $query, mixed $value): void {
            $slugs = $this->listValues($value);

            if ($slugs !== []) {
                $query->whereIn('slug', $slugs);
            }
        };

        return [
            'tag' => $bySlug,
            'tags' => $bySlug,
            'q' => $this->searchFilter(),
        ];
    }

    /**
     * The columns `filter[q]` matches partially (§4.8).
     *
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['name', 'description'];
    }

    /**
     * The attribute names `fields[tags]` may select (§4.9).
     *
     * The same list the embedded `tags` of every content resource is judged
     * against: it lives on the base controller so the two spellings of one type
     * cannot drift (§4.9).
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return $this->tagFields();
    }

    /**
     * The relationships a client may embed: none (§4.9).
     *
     * A tag is shared through the polymorphic pivot, so it has no relationship
     * of its own and nothing to embed. Leaving this to the inherited `['tags']`
     * would make Spatie eager-load a `tags` relation `Tag` does not have, which
     * is a `RelationNotFoundException` — a 500 — rather than the 400 §4.9 gives
     * an endpoint that does not declare an include.
     *
     * @return list<string>
     */
    protected function includes(): array
    {
        return [];
    }

    /**
     * The attribute names a write may set (§4.5).
     *
     * @return list<string>
     */
    protected function writableAttributes(): array
    {
        return ['name', 'description', 'is_placeholder'];
    }

    /**
     * `POST /api/v1/tags` — create one tag (§4.5).
     */
    public function store(StoreTagRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/tags/{tag}` — full replacement (§4.5).
     *
     * The id is resolved here rather than by implicit route-model binding:
     * `Tag::getRouteKeyName()` is `slug`, because the public site addresses a
     * tag as `/tags/{tag:slug}`, and the API binds the numeric id (§2.4, §4.2).
     */
    public function update(UpdateTagRequest $request, string $tag): JsonResponse
    {
        return $this->amend($request, $this->findForWrite($tag));
    }

    /**
     * `PATCH /api/v1/tags/{tag}` — partial update (§4.5).
     */
    public function patch(UpdateTagRequest $request, string $tag): JsonResponse
    {
        return $this->amend($request, $this->findForWrite($tag));
    }

    /**
     * `DELETE /api/v1/tags/{tag}` — delete one tag (§4.5).
     */
    public function destroy(string $tag): Response
    {
        return $this->remove($this->findForWrite($tag));
    }
}
