<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Api\V1\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `projects` — Table A row 5 (§4.2).
 *
 * One of the two resources with a per-type filter beyond the tag filters: a
 * project is featured or it is not, and the collection sorts by `-started_on` by
 * default (§4.8).
 */
class ProjectController extends ApiController
{
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
     * The API Resource that shapes one record.
     *
     * @return class-string<ProjectResource>
     */
    protected function resourceClass(): string
    {
        return ProjectResource::class;
    }

    /**
     * The sort applied when the request carries none (§4.8).
     */
    protected function defaultSort(): string
    {
        return '-started_on';
    }

    /**
     * The sortable fields, without the direction prefix (§4.8).
     *
     * @return list<string>
     */
    protected function allowedSorts(): array
    {
        return ['started_on', 'title'];
    }

    /**
     * The allowed filters (§4.8): the tag filters, a partial `q`, and `featured`.
     */
    protected function allowedFilters(): array
    {
        return [
            ...$this->tagFilters(),
            'q' => $this->searchFilter(),
            'featured' => function (Builder $query, mixed $value): void {
                $query->where('is_featured', $this->booleanValue('featured', $value));
            },
        ];
    }

    /**
     * The attribute names `fields[projects]` may select (§4.9).
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return [
            ...$this->sharedFields(),
            'repo_url',
            'live_url',
            'started_on',
            'ended_on',
            'is_featured',
            'cover_url',
            'cover_variants',
            'cover_alt',
            'screenshots',
        ];
    }

    /**
     * The attribute names a write may set (§4.5).
     *
     * @return list<string>
     */
    protected function writableAttributes(): array
    {
        return [
            ...$this->sharedWritableAttributes(),
            'repo_url',
            'live_url',
            'started_on',
            'ended_on',
            'is_featured',
        ];
    }

    /**
     * `POST /api/v1/projects` — create one record (§4.5).
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/projects/{project}` — full replacement (§4.5).
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        return $this->amend($request, $project);
    }

    /**
     * `PATCH /api/v1/projects/{project}` — partial update (§4.5).
     */
    public function patch(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        return $this->amend($request, $project);
    }

    /**
     * `DELETE /api/v1/projects/{project}` — soft delete (§4.5).
     */
    public function destroy(Project $project): Response
    {
        return $this->remove($project);
    }
}
