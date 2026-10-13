<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\Certification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `certifications` resource (§4.2).
 *
 * The expiry state the public badge renders is derivable from `expires_on` and
 * is deliberately not an attribute: the API states the date, the client decides
 * what "expiring" means (§2.3).
 */
class CertificationResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Certification $certification */
        $certification = $this->resource;

        return [
            'title' => $certification->title,
            'slug' => $certification->slug,
            'summary' => $certification->summary,
            'body' => $certification->body,
            'issuer' => $certification->issuer,
            'credential_id' => $certification->credential_id,
            'credential_url' => $certification->credential_url,
            'issued_on' => $this->isoDate($certification->issued_on),
            'expires_on' => $this->isoDate($certification->expires_on),
            'published_at' => $this->isoTimestamp($certification->published_at),
            'is_published' => $this->isPublished(),
            'is_placeholder' => $certification->is_placeholder,
            'created_at' => $this->isoTimestamp($certification->created_at),
            'updated_at' => $this->isoTimestamp($certification->updated_at),
            ...$this->derivedMediaAttributes('badge_url', 'badge_alt'),
        ];
    }

    /**
     * Resolve the certification's derived media attributes (§4.4, §9.10).
     *
     * `badge_alt` is the stored `media.alt` verbatim: §9.2 permits `NULL` for
     * `badge`, which means "no authored alt recorded" and leaves §7.8's
     * deterministic rule — the badge is named only when it is a link, and empty
     * when the certification name is adjacent — to the renderer.
     *
     * @return array<string, mixed>
     */
    protected function derivedMediaAttributes(string ...$keys): array
    {
        $badge = $this->processedMedia('badge')->first();

        return $this->derivedMediaValues($keys, [
            'badge_url' => $badge === null ? null : $this->primaryMediaUrl($badge),
            'badge_alt' => $badge?->alt,
        ]);
    }

    /**
     * Get the resource object's `self` link.
     */
    protected function selfLink(): string
    {
        return route('api.v1.certifications.show', ['certification' => $this->model()->getKey()]);
    }
}
