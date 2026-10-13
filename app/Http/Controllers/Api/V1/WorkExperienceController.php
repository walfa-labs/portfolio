<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreWorkExperienceRequest;
use App\Http\Requests\Api\V1\UpdateWorkExperienceRequest;
use App\Http\Resources\Api\V1\WorkExperienceResource;
use App\Models\WorkExperience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `work_experiences` — Table A row 1 (§4.2).
 *
 * The public page is `/experience` (singular, canonical); the API resource is
 * `work_experiences` (plural), and the collection sorts by `-end_date` by
 * default (§4.8).
 */
class WorkExperienceController extends ApiController
{
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
     * The API Resource that shapes one record.
     *
     * @return class-string<WorkExperienceResource>
     */
    protected function resourceClass(): string
    {
        return WorkExperienceResource::class;
    }

    /**
     * The sort applied when the request carries none (§4.8).
     */
    protected function defaultSort(): string
    {
        return '-end_date';
    }

    /**
     * The sortable fields, without the direction prefix (§4.8).
     *
     * @return list<string>
     */
    protected function allowedSorts(): array
    {
        return ['end_date', 'title'];
    }

    /**
     * The allowed filters (§4.8): the tag filters, a partial `q`, and nothing
     * else — `work_experiences` has no per-type filter.
     */
    protected function allowedFilters(): array
    {
        return [
            ...$this->tagFilters(),
            'q' => $this->searchFilter(),
        ];
    }

    /**
     * The attribute names `fields[work_experiences]` may select (§4.9).
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return [
            ...$this->sharedFields(),
            'employer',
            'employer_url',
            'location',
            'start_date',
            'end_date',
            'highlights',
            'logo_url',
            'logo_alt',
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
            'employer',
            'employer_url',
            'location',
            'start_date',
            'end_date',
            'highlights',
        ];
    }

    /**
     * `POST /api/v1/work-experiences` — create one record (§4.5).
     */
    public function store(StoreWorkExperienceRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/work-experiences/{work_experience}` — full replacement (§4.5).
     */
    public function update(UpdateWorkExperienceRequest $request, WorkExperience $workExperience): JsonResponse
    {
        return $this->amend($request, $workExperience);
    }

    /**
     * `PATCH /api/v1/work-experiences/{work_experience}` — partial update (§4.5).
     */
    public function patch(UpdateWorkExperienceRequest $request, WorkExperience $workExperience): JsonResponse
    {
        return $this->amend($request, $workExperience);
    }

    /**
     * `DELETE /api/v1/work-experiences/{work_experience}` — soft delete (§4.5).
     */
    public function destroy(WorkExperience $workExperience): Response
    {
        return $this->remove($workExperience);
    }
}
