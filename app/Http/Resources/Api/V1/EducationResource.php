<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\Education;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `educations` resource (§4.2).
 *
 * The type is declared explicitly: `Str::pluralStudly('education')` returns
 * `education`, not the `educations` Table A fixes (§2.3).
 */
class EducationResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Education $education */
        $education = $this->resource;

        return [
            'title' => $education->title,
            'slug' => $education->slug,
            'summary' => $education->summary,
            'body' => $education->body,
            'institution' => $education->institution,
            'institution_url' => $education->institution_url,
            'field_of_study' => $education->field_of_study,
            'start_date' => $this->isoDate($education->start_date),
            'end_date' => $this->isoDate($education->end_date),
            'published_at' => $this->isoTimestamp($education->published_at),
            'is_published' => $this->isPublished(),
            'is_placeholder' => $education->is_placeholder,
            'created_at' => $this->isoTimestamp($education->created_at),
            'updated_at' => $this->isoTimestamp($education->updated_at),
            ...$this->derivedMediaAttributes('logo_url', 'logo_alt'),
        ];
    }

    /**
     * Resolve the institution's derived media attributes (§4.4, §9.10).
     *
     * `logo_alt` is the stored `media.alt` verbatim: §9.2 permits `NULL` for
     * `logo`, which means "no authored alt recorded" and leaves §7.8's
     * deterministic rule — `"{Institution name} crest"`, or empty when the name
     * is adjacent — to the renderer.
     *
     * @return array<string, mixed>
     */
    protected function derivedMediaAttributes(string ...$keys): array
    {
        $logo = $this->processedMedia('logo')->first();

        return $this->derivedMediaValues($keys, [
            'logo_url' => $logo === null ? null : $this->primaryMediaUrl($logo),
            'logo_alt' => $logo?->alt,
        ]);
    }

    /**
     * Get the JSON:API type, which pluralises irregularly (§4.2).
     */
    public function toType(Request $request): string
    {
        return 'educations';
    }

    /**
     * Get the resource object's `self` link.
     */
    protected function selfLink(): string
    {
        return route('api.v1.educations.show', ['education' => $this->model()->getKey()]);
    }
}
