<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\Writing;
use App\Models\WritingSeries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `writings` resource (§4.2, §4.4.2).
 *
 * Two members are carve-outs: `body_markdown` is the Markdown source the
 * `body` column holds (the rendered `body_html` and the derived
 * `reading_time_minutes` are server-side only, §2.3), and `series` is a
 * read-only attribute object rather than a relationship — it is `null` when the
 * writing has no series and `include=series` is a `400` (§4.4.2, §4.9).
 */
class WritingResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Writing $writing */
        $writing = $this->resource;

        return [
            'title' => $writing->title,
            'slug' => $writing->slug,
            'summary' => $writing->summary,
            'excerpt' => $writing->excerpt,
            'body_markdown' => $writing->body,
            'reading_time_minutes' => $writing->reading_time_minutes,
            'series' => $this->series($writing->series),
            'published_at' => $this->isoTimestamp($writing->published_at),
            'is_published' => $this->isPublished(),
            'is_placeholder' => $writing->is_placeholder,
            'created_at' => $this->isoTimestamp($writing->created_at),
            'updated_at' => $this->isoTimestamp($writing->updated_at),
            ...$this->derivedMediaAttributes('cover_url', 'cover_variants', 'cover_alt'),
            'og_image_path' => $writing->og_image_path,
        ];
    }

    /**
     * Resolve the writing's derived media attributes (§4.4, §9.10).
     *
     * `cover` and `inline` are the authored-content collections: §9.6 requires
     * either a non-empty `alt` or an explicit decorative flag, so `cover_alt` is
     * the authored text and is never inferred (§7.8).
     *
     * @return array<string, mixed>
     */
    protected function derivedMediaAttributes(string ...$keys): array
    {
        $cover = $this->processedMedia('cover')->first();

        return $this->derivedMediaValues($keys, [
            'cover_url' => $cover === null ? null : $this->primaryMediaUrl($cover),
            'cover_variants' => $cover === null ? null : $this->mediaVariants($cover),
            'cover_alt' => $cover?->alt,
        ]);
    }

    /**
     * Get the resource object's `self` link.
     */
    protected function selfLink(): string
    {
        return route('api.v1.writings.show', ['writing' => $this->model()->getKey()]);
    }

    /**
     * Shape the writing's series as the attribute object §4.4.2 documents.
     *
     * @return array{id: string, title: string, slug: string}|null
     */
    protected function series(?WritingSeries $series): ?array
    {
        if ($series === null) {
            return null;
        }

        return [
            'id' => (string) $series->getKey(),
            'title' => $series->title,
            'slug' => $series->slug,
        ];
    }
}
