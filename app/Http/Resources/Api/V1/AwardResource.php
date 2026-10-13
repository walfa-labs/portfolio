<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\Award;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `awards` resource (§4.2).
 *
 * The only resource with no derived media attributes: an award carries no media
 * in v1 (§9.1).
 */
class AwardResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Award $award */
        $award = $this->resource;

        return [
            'title' => $award->title,
            'slug' => $award->slug,
            'summary' => $award->summary,
            'body' => $award->body,
            'awarding_body' => $award->awarding_body,
            'awarded_at' => $this->isoDate($award->awarded_at),
            'published_at' => $this->isoTimestamp($award->published_at),
            'is_published' => $this->isPublished(),
            'is_placeholder' => $award->is_placeholder,
            'created_at' => $this->isoTimestamp($award->created_at),
            'updated_at' => $this->isoTimestamp($award->updated_at),
        ];
    }

    /**
     * Get the resource object's `self` link.
     */
    protected function selfLink(): string
    {
        return route('api.v1.awards.show', ['award' => $this->model()->getKey()]);
    }
}
