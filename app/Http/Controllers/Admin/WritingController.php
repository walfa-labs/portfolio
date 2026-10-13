<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentType;
use App\Http\Requests\Admin\StoreWritingRequest;
use App\Http\Requests\Admin\UpdateWritingRequest;
use App\Models\Tag;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * `/admin/writings` — the posts CRUD (§5.8).
 *
 * Two carve-outs. The list is ordered by `published_at` rather than by
 * `sort_order`, so there is no `reorder` action here at all — a control that
 * could not change the order it sits in is worse than no control (§5.9). And
 * `body_html` and `reading_time_minutes` are derived from `body` on save
 * (§2.6), so they are published to the form read-only and never written.
 */
class WritingController extends AdminController
{
    /**
     * The content type this resource serves.
     */
    protected function contentType(): ContentType
    {
        return ContentType::Writing;
    }

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
     * The list is not reorderable: its order is `published_at` (§5.9).
     */
    protected function orderable(): bool
    {
        return false;
    }

    /**
     * Order the list by `published_at`, newest first, then by `id` (§5.9).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyDefaultOrder(Builder $query): Builder
    {
        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * The fields a second post in the same series would re-ask.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return ['series_id'];
    }

    /**
     * One post as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var Writing $record */
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
            'excerpt' => $record->excerpt,
            'series_id' => $record->series_id,
            'og_image_path' => $record->og_image_path,
            'reading_time_minutes' => $record->reading_time_minutes,
        ];
    }

    /**
     * `POST /admin/writings` — create one post.
     */
    public function store(StoreWritingRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/writings/{writing}` — update one post.
     */
    public function update(UpdateWritingRequest $request, Writing $writing): RedirectResponse
    {
        return $this->updateRecord($request, $writing);
    }

    /**
     * `GET /admin/writings/{writing}/edit` — the populated form.
     */
    public function edit(Writing $writing): Response
    {
        return $this->editPage($writing);
    }

    /**
     * `DELETE /admin/writings/{writing}` — delete one post.
     */
    public function destroy(Writing $writing): RedirectResponse
    {
        return $this->destroyRecord($writing);
    }

    /**
     * `PATCH /admin/writings/{writing}/publish`.
     */
    public function publish(Writing $writing): RedirectResponse
    {
        return $this->publishRecord($writing);
    }

    /**
     * `PATCH /admin/writings/{writing}/unpublish`.
     */
    public function unpublish(Writing $writing): RedirectResponse
    {
        return $this->unpublishRecord($writing);
    }
}
