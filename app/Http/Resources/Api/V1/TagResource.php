<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `tags` resource (§4.2).
 *
 * A full six-method resource like every other row of Table A, and the only one
 * with no relationships: tags are shared through the polymorphic pivot, so a
 * tag has nothing to embed (§4.9).
 */
class TagResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Tag $tag */
        $tag = $this->resource;

        return [
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'is_placeholder' => $tag->is_placeholder,
            'created_at' => $this->isoTimestamp($tag->created_at),
            'updated_at' => $this->isoTimestamp($tag->updated_at),
        ];
    }

    /**
     * Get the resource's relationships: a tag has none (§4.9).
     *
     * @return array<string, class-string>
     */
    public function toRelationships(Request $request): array
    {
        return [];
    }

    /**
     * Get the resource object's `self` link.
     *
     * The id, never the slug: the API binds by id and the public site binds by
     * slug (§4.2).
     */
    protected function selfLink(): string
    {
        return route('api.v1.tags.show', ['tag' => $this->model()->getKey()]);
    }
}
