<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StorePublicationRequest;
use App\Http\Requests\Api\V1\UpdatePublicationRequest;
use App\Http\Resources\Api\V1\PublicationResource;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `publications` — Table A row 4 (§4.2).
 *
 * The collection sorts by `-issued_on` by default (§4.8); `doi_url` is derived
 * from `doi` and read-only, so only `doi` is writable.
 */
class PublicationController extends ApiController
{
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
     * The API Resource that shapes one record.
     *
     * @return class-string<PublicationResource>
     */
    protected function resourceClass(): string
    {
        return PublicationResource::class;
    }

    /**
     * The sort applied when the request carries none (§4.8).
     */
    protected function defaultSort(): string
    {
        return '-issued_on';
    }

    /**
     * The sortable fields, without the direction prefix (§4.8).
     *
     * @return list<string>
     */
    protected function allowedSorts(): array
    {
        return ['issued_on', 'title'];
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
     * The attribute names `fields[publications]` may select (§4.9).
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return [
            ...$this->sharedFields(),
            'venue',
            'authors',
            'doi',
            'doi_url',
            'issued_on',
            'pdf_url',
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
            'venue',
            'authors',
            'doi',
            'issued_on',
        ];
    }

    /**
     * `POST /api/v1/publications` — create one record (§4.5).
     */
    public function store(StorePublicationRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/publications/{publication}` — full replacement (§4.5).
     */
    public function update(UpdatePublicationRequest $request, Publication $publication): JsonResponse
    {
        return $this->amend($request, $publication);
    }

    /**
     * `PATCH /api/v1/publications/{publication}` — partial update (§4.5).
     */
    public function patch(UpdatePublicationRequest $request, Publication $publication): JsonResponse
    {
        return $this->amend($request, $publication);
    }

    /**
     * `DELETE /api/v1/publications/{publication}` — soft delete (§4.5).
     */
    public function destroy(Publication $publication): Response
    {
        return $this->remove($publication);
    }
}
