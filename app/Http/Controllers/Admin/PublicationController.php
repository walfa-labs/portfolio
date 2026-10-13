<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentType;
use App\Http\Requests\Admin\StorePublicationRequest;
use App\Http\Requests\Admin\UpdatePublicationRequest;
use App\Models\Publication;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * `/admin/publications` — the papers CRUD (§5.8).
 *
 * `doi_url` is derived from `doi` on the model and is never writable, so the
 * payload carries only the identifier the admin types.
 */
class PublicationController extends AdminController
{
    /**
     * The content type this resource serves.
     */
    protected function contentType(): ContentType
    {
        return ContentType::Publication;
    }

    /**
     * The model the resource is built on.
     *
     * @return class-string<Publication>
     */
    protected function modelClass(): string
    {
        return Publication::class;
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
     * The fields a second paper in the same venue would re-ask.
     *
     * `doi` and `authors` are deliberately absent: both identify one paper, so
     * carrying them over would prefill the wrong answer.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return ['venue', 'issued_on'];
    }

    /**
     * One paper as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var Publication $record */
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
            'venue' => $record->venue,
            'authors' => $record->authors,
            'doi' => $record->doi,
            'issued_on' => $record->issued_on->toDateString(),
        ];
    }

    /**
     * `POST /admin/publications` — create one paper.
     */
    public function store(StorePublicationRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/publications/{publication}` — update one paper.
     */
    public function update(UpdatePublicationRequest $request, Publication $publication): RedirectResponse
    {
        return $this->updateRecord($request, $publication);
    }

    /**
     * `GET /admin/publications/{publication}/edit` — the populated form.
     */
    public function edit(Publication $publication): Response
    {
        return $this->editPage($publication);
    }

    /**
     * `DELETE /admin/publications/{publication}` — delete one paper.
     */
    public function destroy(Publication $publication): RedirectResponse
    {
        return $this->destroyRecord($publication);
    }

    /**
     * `PATCH /admin/publications/{publication}/publish`.
     */
    public function publish(Publication $publication): RedirectResponse
    {
        return $this->publishRecord($publication);
    }

    /**
     * `PATCH /admin/publications/{publication}/unpublish`.
     */
    public function unpublish(Publication $publication): RedirectResponse
    {
        return $this->unpublishRecord($publication);
    }

    /**
     * `PATCH /admin/publications/reorder` — apply the ordered `ids` array.
     */
    public function reorder(Request $request): RedirectResponse
    {
        return $this->reorderRecords($request);
    }
}
