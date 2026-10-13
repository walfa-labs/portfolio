<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreAwardRequest;
use App\Http\Requests\Api\V1\UpdateAwardRequest;
use App\Http\Resources\Api\V1\AwardResource;
use App\Models\Award;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `awards` — Table A row 6 (§4.2).
 *
 * The collection sorts by `-awarded_at` by default (§4.8), and `awarded_at` is
 * a date rather than a timestamp, so a sparse fieldset receives `2026-03-01`.
 */
class AwardController extends ApiController
{
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
     * The API Resource that shapes one record.
     *
     * @return class-string<AwardResource>
     */
    protected function resourceClass(): string
    {
        return AwardResource::class;
    }

    /**
     * The sort applied when the request carries none (§4.8).
     */
    protected function defaultSort(): string
    {
        return '-awarded_at';
    }

    /**
     * The sortable fields, without the direction prefix (§4.8).
     *
     * @return list<string>
     */
    protected function allowedSorts(): array
    {
        return ['awarded_at', 'title'];
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
     * The attribute names `fields[awards]` may select (§4.9).
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return [
            ...$this->sharedFields(),
            'awarding_body',
            'awarded_at',
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
            'awarding_body',
            'awarded_at',
        ];
    }

    /**
     * `POST /api/v1/awards` — create one record (§4.5).
     */
    public function store(StoreAwardRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/awards/{award}` — full replacement (§4.5).
     */
    public function update(UpdateAwardRequest $request, Award $award): JsonResponse
    {
        return $this->amend($request, $award);
    }

    /**
     * `PATCH /api/v1/awards/{award}` — partial update (§4.5).
     */
    public function patch(UpdateAwardRequest $request, Award $award): JsonResponse
    {
        return $this->amend($request, $award);
    }

    /**
     * `DELETE /api/v1/awards/{award}` — soft delete (§4.5).
     */
    public function destroy(Award $award): Response
    {
        return $this->remove($award);
    }
}
