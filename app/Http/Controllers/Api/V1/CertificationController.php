<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreCertificationRequest;
use App\Http\Requests\Api\V1\UpdateCertificationRequest;
use App\Http\Resources\Api\V1\CertificationResource;
use App\Models\Certification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `certifications` — Table A row 3 (§4.2).
 *
 * The only resource with a per-type boolean filter beyond `projects`: a
 * certificate is expired when it has an `expires_on` in the past, and a
 * certificate with no expiry never expires (§4.8).
 */
class CertificationController extends ApiController
{
    /**
     * The model the resource is built on.
     *
     * @return class-string<Certification>
     */
    protected function modelClass(): string
    {
        return Certification::class;
    }

    /**
     * The API Resource that shapes one record.
     *
     * @return class-string<CertificationResource>
     */
    protected function resourceClass(): string
    {
        return CertificationResource::class;
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
     * The allowed filters (§4.8): the tag filters, a partial `q`, and `expired`.
     */
    protected function allowedFilters(): array
    {
        return [
            ...$this->tagFilters(),
            'q' => $this->searchFilter(),
            'expired' => function (Builder $query, mixed $value): void {
                $today = today()->toDateString();

                if ($this->booleanValue('expired', $value)) {
                    $query->whereNotNull('expires_on')->where('expires_on', '<', $today);

                    return;
                }

                $query->where(fn (Builder $query): Builder => $query
                    ->whereNull('expires_on')
                    ->orWhere('expires_on', '>=', $today));
            },
        ];
    }

    /**
     * The attribute names `fields[certifications]` may select (§4.9).
     *
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return [
            ...$this->sharedFields(),
            'issuer',
            'credential_id',
            'credential_url',
            'issued_on',
            'expires_on',
            'badge_url',
            'badge_alt',
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
            'issuer',
            'credential_id',
            'credential_url',
            'issued_on',
            'expires_on',
        ];
    }

    /**
     * `POST /api/v1/certifications` — create one record (§4.5).
     */
    public function store(StoreCertificationRequest $request): JsonResponse
    {
        return $this->create($request);
    }

    /**
     * `PUT /api/v1/certifications/{certification}` — full replacement (§4.5).
     */
    public function update(UpdateCertificationRequest $request, Certification $certification): JsonResponse
    {
        return $this->amend($request, $certification);
    }

    /**
     * `PATCH /api/v1/certifications/{certification}` — partial update (§4.5).
     */
    public function patch(UpdateCertificationRequest $request, Certification $certification): JsonResponse
    {
        return $this->amend($request, $certification);
    }

    /**
     * `DELETE /api/v1/certifications/{certification}` — soft delete (§4.5).
     */
    public function destroy(Certification $certification): Response
    {
        return $this->remove($certification);
    }
}
