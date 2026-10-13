<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `publications` resource (§4.2).
 *
 * `doi` is the bare identifier; `doi_url` is the resolver URL the model derives
 * from it, exposed read-only (§2.3).
 */
class PublicationResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Publication $publication */
        $publication = $this->resource;

        return [
            'title' => $publication->title,
            'slug' => $publication->slug,
            'summary' => $publication->summary,
            'body' => $publication->body,
            'venue' => $publication->venue,
            'authors' => $publication->authors,
            'doi' => $publication->doi,
            'doi_url' => $publication->doi_url,
            'issued_on' => $this->isoDate($publication->issued_on),
            'published_at' => $this->isoTimestamp($publication->published_at),
            'is_published' => $this->isPublished(),
            'is_placeholder' => $publication->is_placeholder,
            'created_at' => $this->isoTimestamp($publication->created_at),
            'updated_at' => $this->isoTimestamp($publication->updated_at),
            ...$this->derivedMediaAttributes('pdf_url'),
        ];
    }

    /**
     * Resolve the publication's derived media attributes (§4.4, §9.10).
     *
     * `pdf` has no variant matrix (§9.5): the served object is the uploaded
     * document itself, addressed through the serving route by the `original.{ext}`
     * key §9.4 gives every stored original.
     *
     * @return array<string, mixed>
     */
    protected function derivedMediaAttributes(string ...$keys): array
    {
        $pdf = $this->processedMedia('pdf')->first();

        return $this->derivedMediaValues($keys, [
            'pdf_url' => $pdf === null ? null : $this->primaryMediaUrl($pdf),
        ]);
    }

    /**
     * Get the resource object's `self` link.
     */
    protected function selfLink(): string
    {
        return route('api.v1.publications.show', ['publication' => $this->model()->getKey()]);
    }
}
