<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreEducationRequest;
use App\Http\Requests\Api\V1\UpdateEducationRequest;
use App\Http\Resources\Api\V1\EducationResource;
use App\Models\Education;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `educations` — Table A row 2 (§4.2).
 *
 * The JSON:API type is the plural of the canonical singular (`education`), and
 * the collection sorts by `-end_date` by default (§4.8).
 */
class EducationController extends ApiController
{
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
     * The API Resource that shapes one record.
     *
     * @return class-string<EducationResource>
     */
    protected function resourceClass(): string
    {
        return EducationResource::class;
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
     * The allowed filters (§4.8): the tag filters and a partial `q`.
     */
    protected function allowedFilters(): array
    {
        return [
            ...$this->tagFilters(),
            'q' => $this->searchFilter(),
        ];
    }

    /**
     * The attribute names `fields[educations]` may select (§4.9).
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return [
            ...$this->sharedFields(),
            'institution',
            'institution_url',
            'field_of_study',
            'start_date',
            'end_date',
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
            'institution',
            'institution_url',
            'field_of_study',
            'start_date',
            'end_date',
        ];
    }

    /**
     * `POST /api/v1/educations` — create one record (§4.5).
     */
    public function store(StoreEducationRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/educations/{education}` — full replacement (§4.5).
     */
    public function update(UpdateEducationRequest $request, Education $education): JsonResponse
    {
        return $this->amend($request, $education);
    }

    /**
     * `PATCH /api/v1/educations/{education}` — partial update (§4.5).
     */
    public function patch(UpdateEducationRequest $request, Education $education): JsonResponse
    {
        return $this->amend($request, $education);
    }

    /**
     * `DELETE /api/v1/educations/{education}` — soft delete (§4.5).
     */
    public function destroy(Education $education): Response
    {
        return $this->remove($education);
    }
}
