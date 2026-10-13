<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\StoreTagRequest;
use App\Http\Requests\Admin\UpdateTagRequest;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * `/admin/tags` — the tags CRUD (§5.8).
 *
 * `tags` is the odd one out among the eight, in four ways, and each is declared
 * here rather than guessed at:
 *
 * - it is not a `ContentType`, so its three spellings — `tag`, `tags`, `Tag` —
 *   are written down instead of derived from the enum;
 * - it has no publication state (§2.5), so `publish`, `unpublish` and the two
 *   bulk verbs are policy-checked no-ops that say so, and the index's
 *   `meta.publishable` is `false` so the page never offers the control;
 * - it is ordered by the ASCII-normalised key `LOWER(name), id` rather than by
 *   `sort_order`, so it is not reorderable;
 * - it is addressed by its own route key. `Tag::getRouteKeyName()` is `slug`,
 *   because the public site serves `/tags/{tag:slug}` — so the admin accepts
 *   either the primary key every other admin resource uses or the slug the
 *   model itself would generate.
 */
class TagController extends AdminController
{
    /**
     * The singular snake_case name of the resource.
     */
    protected function type(): string
    {
        return 'tag';
    }

    /**
     * The plural kebab-case route segment.
     */
    protected function routeSegment(): string
    {
        return 'tags';
    }

    /**
     * The human-readable resource label.
     */
    protected function label(): string
    {
        return 'Tag';
    }

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
     * The list is not reorderable: its order is `LOWER(name), id` (§5.9).
     */
    protected function orderable(): bool
    {
        return false;
    }

    /**
     * Order the list by the ASCII-normalised key `LOWER(name)`, then by `id`.
     *
     * The raw `name` column would sort by the engine's collation, which differs
     * between SQLite and MariaDB — `Zebra` before `apple` on one and after it on
     * the other. `LOWER()` is the portable spelling of the pinned key, and the
     * `id` tiebreaker keeps two tags whose names differ only in case in a stable
     * order (§2.9 item 4).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyDefaultOrder(Builder $query): Builder
    {
        return $query->orderByRaw('LOWER(name)')->orderBy('id');
    }

    /**
     * The columns `?q=` matches partially.
     *
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['name', 'description'];
    }

    /**
     * Narrow the list to the tag slugs named by `?tag=`.
     *
     * A tag is not tagged with anything, so the filter means the tag's own
     * slug — the same reading the API gives it (§4.8).
     *
     * @param  Builder<Model>  $query
     */
    protected function applyTagFilter(Builder $query, string $tag): void
    {
        $slugs = $this->listValues($tag);

        if ($slugs === []) {
            return;
        }

        $query->whereIn('slug', $slugs);
    }

    /**
     * One tag as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var Tag $record */
        $record = $model;

        return [
            'id' => $record->id,
            'name' => $record->name,
            'slug' => $record->slug,
            'description' => $record->description,
            'is_placeholder' => $record->is_placeholder,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    /**
     * `POST /admin/tags` — create one tag.
     */
    public function store(StoreTagRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/tags/{tag}` — update one tag.
     */
    public function update(UpdateTagRequest $request, string $tag): RedirectResponse
    {
        return $this->updateRecord($request, $this->findTag($tag));
    }

    /**
     * `GET /admin/tags/{tag}/edit` — the populated form.
     */
    public function edit(string $tag): Response
    {
        return $this->editPage($this->findTag($tag));
    }

    /**
     * `DELETE /admin/tags/{tag}` — delete one tag.
     */
    public function destroy(string $tag): RedirectResponse
    {
        return $this->destroyRecord($this->findTag($tag));
    }

    /**
     * `PATCH /admin/tags/{tag}/publish` — authorise, then report the no-op.
     *
     * A tag has no `status` and no `published_at` (§2.5), so there is nothing to
     * set. The ability is still checked, because the §5.8 route set gives every
     * resource the same verbs and a non-administrator must not learn from the
     * response that this one differs.
     */
    public function publish(string $tag): RedirectResponse
    {
        return $this->publishRecord($this->findTag($tag));
    }

    /**
     * `PATCH /admin/tags/{tag}/unpublish` — authorise, then report the no-op.
     */
    public function unpublish(string $tag): RedirectResponse
    {
        return $this->unpublishRecord($this->findTag($tag));
    }

    /**
     * Resolve one tag from the route parameter.
     *
     * The admin addresses a tag by its primary key, like the seven content
     * types; `Tag::getRouteKeyName()` is `slug`, though, so a slug is accepted
     * as well. Without that, a URI built the way the model itself would build
     * one — `route('admin.tags.edit', $tag)`, or a `{tag:slug}` route — would
     * 404 on a tag that plainly exists.
     */
    private function findTag(string $tag): Tag
    {
        $query = Tag::query();

        return (ctype_digit($tag) ? $query->whereKey((int) $tag) : $query->where('slug', $tag))->firstOrFail();
    }
}
