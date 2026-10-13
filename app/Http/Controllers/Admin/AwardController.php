<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentType;
use App\Http\Requests\Admin\StoreAwardRequest;
use App\Http\Requests\Admin\UpdateAwardRequest;
use App\Models\Award;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * `/admin/awards` — the awards CRUD (§5.8).
 *
 * The one content type with no `HasMedia` trait: §7.8 gives an award no image,
 * so nothing here mentions one.
 */
class AwardController extends AdminController
{
    /**
     * The content type this resource serves.
     */
    protected function contentType(): ContentType
    {
        return ContentType::Award;
    }

    /**
     * The model the resource is built on.
     *
     * @return class-string<Award>
     */
    protected function modelClass(): string
    {
        return Award::class;
    }

    /**
     * Order the list by `sort_order`, then by `id` (§5.9).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The fields a second award from the same body would re-ask.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return ['awarding_body'];
    }

    /**
     * One award as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var Award $record */
        $record = $model;

        return [
            'id' => $record->id,
            'title' => $record->title,
            'slug' => $record->slug,
            'summary' => $record->summary,
            'body' => $record->body,
            'status' => $record->status->value,
            'published_at' => $record->published_at?->toIso8601String(),
            'is_placeholder' => $record->is_placeholder,
            'meta_title' => $record->meta_title,
            'meta_description' => $record->meta_description,
            'sort_order' => $record->sort_order,
            'updated_at' => $record->updated_at?->toIso8601String(),
            'tag_ids' => $record->tags->map(fn (Tag $tag): int => $tag->id)->values()->all(),
            'tags' => $record->tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()->all(),
            'awarding_body' => $record->awarding_body,
            'awarded_at' => $record->awarded_at->toDateString(),
        ];
    }

    /**
     * `POST /admin/awards` — create one award.
     */
    public function store(StoreAwardRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/awards/{award}` — update one award.
     */
    public function update(UpdateAwardRequest $request, Award $award): RedirectResponse
    {
        return $this->updateRecord($request, $award);
    }

    /**
     * `GET /admin/awards/{award}/edit` — the populated form.
     */
    public function edit(Award $award): Response
    {
        return $this->editPage($award);
    }

    /**
     * `DELETE /admin/awards/{award}` — delete one award.
     */
    public function destroy(Award $award): RedirectResponse
    {
        return $this->destroyRecord($award);
    }

    /**
     * `PATCH /admin/awards/{award}/publish`.
     */
    public function publish(Award $award): RedirectResponse
    {
        return $this->publishRecord($award);
    }

    /**
     * `PATCH /admin/awards/{award}/unpublish`.
     */
    public function unpublish(Award $award): RedirectResponse
    {
        return $this->unpublishRecord($award);
    }

    /**
     * `PATCH /admin/awards/reorder` — apply the ordered `ids` array.
     */
    public function reorder(Request $request): RedirectResponse
    {
        return $this->reorderRecords($request);
    }
}
