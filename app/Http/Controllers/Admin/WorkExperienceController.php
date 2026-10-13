<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentType;
use App\Http\Requests\Admin\StoreWorkExperienceRequest;
use App\Http\Requests\Admin\UpdateWorkExperienceRequest;
use App\Models\Tag;
use App\Models\WorkExperience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * `/admin/work-experiences` — the roles CRUD (§5.8).
 *
 * The six ordered types share one list contract; this is the first of them, and
 * its ordering (`sort_order`, then `id`) is the one `reorder` writes back.
 */
class WorkExperienceController extends AdminController
{
    /**
     * The content type this resource serves.
     */
    protected function contentType(): ContentType
    {
        return ContentType::WorkExperience;
    }

    /**
     * The model the resource is built on.
     *
     * @return class-string<WorkExperience>
     */
    protected function modelClass(): string
    {
        return WorkExperience::class;
    }

    /**
     * Order the list by `sort_order`, then by `id` (§5.9).
     *
     * The `id` tiebreaker is not decoration: every row starts at `sort_order`
     * `0`, so without it the first page of a fresh table would be in whatever
     * order the engine happened to return — and that order is not stable across
     * SQLite and MariaDB (§2.9 item 9).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The fields a second role at the same employer would otherwise re-ask.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return ['employer', 'employer_url', 'location', 'start_date', 'end_date'];
    }

    /**
     * One role as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var WorkExperience $record */
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
            'employer' => $record->employer,
            'employer_url' => $record->employer_url,
            'location' => $record->location,
            'start_date' => $record->start_date->toDateString(),
            'end_date' => $record->end_date?->toDateString(),
            'highlights' => $record->highlights ?? [],
        ];
    }

    /**
     * `POST /admin/work-experiences` — create one role.
     */
    public function store(StoreWorkExperienceRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/work-experiences/{work_experience}` — update one role.
     */
    public function update(UpdateWorkExperienceRequest $request, WorkExperience $workExperience): RedirectResponse
    {
        return $this->updateRecord($request, $workExperience);
    }

    /**
     * `GET /admin/work-experiences/{work_experience}/edit` — the populated form.
     */
    public function edit(WorkExperience $workExperience): Response
    {
        return $this->editPage($workExperience);
    }

    /**
     * `DELETE /admin/work-experiences/{work_experience}` — delete one role.
     */
    public function destroy(WorkExperience $workExperience): RedirectResponse
    {
        return $this->destroyRecord($workExperience);
    }

    /**
     * `PATCH /admin/work-experiences/{work_experience}/publish`.
     */
    public function publish(WorkExperience $workExperience): RedirectResponse
    {
        return $this->publishRecord($workExperience);
    }

    /**
     * `PATCH /admin/work-experiences/{work_experience}/unpublish`.
     */
    public function unpublish(WorkExperience $workExperience): RedirectResponse
    {
        return $this->unpublishRecord($workExperience);
    }

    /**
     * `PATCH /admin/work-experiences/reorder` — apply the ordered `ids` array.
     */
    public function reorder(Request $request): RedirectResponse
    {
        return $this->reorderRecords($request);
    }
}
