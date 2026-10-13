<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentType;
use App\Http\Requests\Admin\StoreCertificationRequest;
use App\Http\Requests\Admin\UpdateCertificationRequest;
use App\Models\Certification;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * `/admin/certifications` — the credentials CRUD (§5.8).
 */
class CertificationController extends AdminController
{
    /**
     * The content type this resource serves.
     */
    protected function contentType(): ContentType
    {
        return ContentType::Certification;
    }

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
     * The fields a second credential from the same issuer would re-ask.
     *
     * `credential_id` is deliberately absent: it identifies one certificate, so
     * carrying it over would prefill the wrong answer rather than save typing.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return ['issuer', 'credential_url', 'issued_on', 'expires_on'];
    }

    /**
     * One credential as the flat payload the list and the form both read.
     *
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $model): array
    {
        /** @var Certification $record */
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
            'issuer' => $record->issuer,
            'credential_id' => $record->credential_id,
            'credential_url' => $record->credential_url,
            'issued_on' => $record->issued_on->toDateString(),
            'expires_on' => $record->expires_on?->toDateString(),
        ];
    }

    /**
     * `POST /admin/certifications` — create one credential.
     */
    public function store(StoreCertificationRequest $request): RedirectResponse
    {
        return $this->storeRecord($request);
    }

    /**
     * `PATCH /admin/certifications/{certification}` — update one credential.
     */
    public function update(UpdateCertificationRequest $request, Certification $certification): RedirectResponse
    {
        return $this->updateRecord($request, $certification);
    }

    /**
     * `GET /admin/certifications/{certification}/edit` — the populated form.
     */
    public function edit(Certification $certification): Response
    {
        return $this->editPage($certification);
    }

    /**
     * `DELETE /admin/certifications/{certification}` — delete one credential.
     */
    public function destroy(Certification $certification): RedirectResponse
    {
        return $this->destroyRecord($certification);
    }

    /**
     * `PATCH /admin/certifications/{certification}/publish`.
     */
    public function publish(Certification $certification): RedirectResponse
    {
        return $this->publishRecord($certification);
    }

    /**
     * `PATCH /admin/certifications/{certification}/unpublish`.
     */
    public function unpublish(Certification $certification): RedirectResponse
    {
        return $this->unpublishRecord($certification);
    }

    /**
     * `PATCH /admin/certifications/reorder` — apply the ordered `ids` array.
     */
    public function reorder(Request $request): RedirectResponse
    {
        return $this->reorderRecords($request);
    }
}
