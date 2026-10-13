<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\WorkExperience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `work_experiences` resource (§4.2).
 *
 * `end_date` is `null` for a current role rather than the string "Present" —
 * the rendering decision belongs to the client (§2.3).
 */
class WorkExperienceResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkExperience $experience */
        $experience = $this->resource;

        return [
            'title' => $experience->title,
            'slug' => $experience->slug,
            'summary' => $experience->summary,
            'body' => $experience->body,
            'employer' => $experience->employer,
            'employer_url' => $experience->employer_url,
            'location' => $experience->location,
            'start_date' => $this->isoDate($experience->start_date),
            'end_date' => $this->isoDate($experience->end_date),
            'highlights' => $experience->highlights ?? [],
            'published_at' => $this->isoTimestamp($experience->published_at),
            'is_published' => $this->isPublished(),
            'is_placeholder' => $experience->is_placeholder,
            'created_at' => $this->isoTimestamp($experience->created_at),
            'updated_at' => $this->isoTimestamp($experience->updated_at),
            ...$this->derivedMediaAttributes('logo_url', 'logo_alt'),
        ];
    }

    /**
     * Resolve the role's derived media attributes (§4.4, §9.10).
     *
     * `logo_alt` is the stored `media.alt` verbatim: §9.2 permits `NULL` for
     * `logo`, which means "no authored alt recorded" and leaves §7.8's
     * deterministic rule — `"{Employer name} logo"`, or empty when the name is
     * adjacent — to the renderer.
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
     * Get the resource object's `self` link.
     */
    protected function selfLink(): string
    {
        return route('api.v1.work-experiences.show', ['work_experience' => $this->model()->getKey()]);
    }
}
