<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentType;
use App\Http\Requests\Admin\StoreEducationRequest;
use App\Http\Requests\Admin\UpdateEducationRequest;
use App\Models\Education;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * `/admin/educations` — the degrees CRUD (§5.8).
 *
 * The route segment is `educations`, not `education`: `Str::plural('education')`
 * returns `education`, so the segment is pinned by {@see ContentType::routeSegment()}
 * rather than derived (§4.2).
 */
class EducationController extends AdminController
{
    /**
     * The content type this resource serves.
     */
    protected function contentType(): ContentType
    {
        return ContentType::Education;
    }

    /**
     * The model the resource is built on.
     *
     * @return class-string<Education>
     */
    protected function modelClass(): string
    {
        return Education::class;
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
     * The fields a second programme at the same institution would re-ask.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return ['institution', 'institution_url', 'field_of_study', 'start_date', 'end_date'];
    }

    /**
     * One programme as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var Education $record */
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
            'updated_at' => $record->updated_at->toIso8601String(),
            'tag_ids' => $record->tags->map(fn (Tag $tag): int => $tag->id)->values()->all(),
            'tags' => $record->tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()->all(),
            'institution' => $record->institution,
            'institution_url' => $record->institution_url,
            'field_of_study' => $record->field_of_study,
            'start_date' => $record->start_date?->toDateString(),
            'end_date' => $record->end_date?->toDateString(),
        ];
    }

    /**
     * `POST /admin/educations` — create one programme.
     */
    public function store(StoreEducationRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/educations/{education}` — update one programme.
     */
    public function update(UpdateEducationRequest $request, Education $education): RedirectResponse
    {
        return $this->updateRecord($request, $education);
    }

    /**
     * `GET /admin/educations/{education}/edit` — the populated form.
     */
    public function edit(Education $education): Response
    {
        return $this->editPage($education);
    }

    /**
     * `DELETE /admin/educations/{education}` — delete one programme.
     */
    public function destroy(Education $education): RedirectResponse
    {
        return $this->destroyRecord($education);
    }

    /**
     * `PATCH /admin/educations/{education}/publish`.
     */
    public function publish(Education $education): RedirectResponse
    {
        return $this->publishRecord($education);
    }

    /**
     * `PATCH /admin/educations/{education}/unpublish`.
     */
    public function unpublish(Education $education): RedirectResponse
    {
        return $this->unpublishRecord($education);
    }

    /**
     * `PATCH /admin/educations/reorder` — apply the ordered `ids` array.
     */
    public function reorder(Request $request): RedirectResponse
    {
        return $this->reorderRecords($request);
    }
}
