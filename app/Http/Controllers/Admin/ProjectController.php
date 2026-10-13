<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentType;
use App\Http\Requests\Admin\StoreProjectRequest;
use App\Http\Requests\Admin\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * `/admin/projects` — the projects CRUD (§5.8).
 *
 * The one content type with a per-type boolean (`is_featured`), which the form
 * carries as an ordinary checkbox field.
 */
class ProjectController extends AdminController
{
    /**
     * The content type this resource serves.
     */
    protected function contentType(): ContentType
    {
        return ContentType::Project;
    }

    /**
     * The model the resource is built on.
     *
     * @return class-string<Project>
     */
    protected function modelClass(): string
    {
        return Project::class;
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
     * The fields a second project in the same repository would re-ask.
     *
     * `live_url` is deliberately absent: it identifies one deployment, so
     * carrying it over would prefill a wrong answer.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return ['repo_url', 'started_on', 'ended_on'];
    }

    /**
     * One project as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var Project $record */
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
            'repo_url' => $record->repo_url,
            'live_url' => $record->live_url,
            'started_on' => $record->started_on?->toDateString(),
            'ended_on' => $record->ended_on?->toDateString(),
            'is_featured' => $record->is_featured,
        ];
    }

    /**
     * `POST /admin/projects` — create one project.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/projects/{project}` — update one project.
     */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        return $this->updateRecord($request, $project);
    }

    /**
     * `GET /admin/projects/{project}/edit` — the populated form.
     */
    public function edit(Project $project): Response
    {
        return $this->editPage($project);
    }

    /**
     * `DELETE /admin/projects/{project}` — delete one project.
     */
    public function destroy(Project $project): RedirectResponse
    {
        return $this->destroyRecord($project);
    }

    /**
     * `PATCH /admin/projects/{project}/publish`.
     */
    public function publish(Project $project): RedirectResponse
    {
        return $this->publishRecord($project);
    }

    /**
     * `PATCH /admin/projects/{project}/unpublish`.
     */
    public function unpublish(Project $project): RedirectResponse
    {
        return $this->unpublishRecord($project);
    }

    /**
     * `PATCH /admin/projects/reorder` — apply the ordered `ids` array.
     */
    public function reorder(Request $request): RedirectResponse
    {
        return $this->reorderRecords($request);
    }
}
