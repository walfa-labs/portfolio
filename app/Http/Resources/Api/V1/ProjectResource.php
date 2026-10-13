<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesJsonApiDocument;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The `projects` resource (§4.2).
 *
 * The tech stack is the `tags` relationship, not an attribute (§2.3), and
 * `screenshots` is the collection's list of derived images, each carrying its
 * own `alt` (§4.4).
 */
class ProjectResource extends JsonApiResource
{
    use ResolvesJsonApiDocument;

    /**
     * Get the resource object's attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        return [
            'title' => $project->title,
            'slug' => $project->slug,
            'summary' => $project->summary,
            'body' => $project->body,
            'repo_url' => $project->repo_url,
            'live_url' => $project->live_url,
            'started_on' => $this->isoDate($project->started_on),
            'ended_on' => $this->isoDate($project->ended_on),
            'is_featured' => $project->is_featured,
            'published_at' => $this->isoTimestamp($project->published_at),
            'is_published' => $this->isPublished(),
            'is_placeholder' => $project->is_placeholder,
            'created_at' => $this->isoTimestamp($project->created_at),
            'updated_at' => $this->isoTimestamp($project->updated_at),
            ...$this->derivedMediaAttributes('cover_url', 'cover_variants', 'cover_alt', 'screenshots'),
        ];
    }

    /**
     * Resolve the project's derived media attributes (§4.4, §9.10).
     *
     * `cover_url`, `cover_variants` and `cover_alt` describe the project's one
     * cover; `screenshots` is every processed `screenshot` row, each an image in
     * its own right with its own `alt`.
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
            'screenshots' => $this->screenshots(),
        ]);
    }

    /**
     * The project's screenshots, in display order.
     *
     * @return list<array{url: string, variants: array<string, array{url: string, width: int, height: int, mime: string}>, alt: string|null}>
     */
    protected function screenshots(): array
    {
        $screenshots = [];

        foreach ($this->processedMedia('screenshot') as $media) {
            $screenshots[] = [
                'url' => $this->primaryMediaUrl($media),
                'variants' => $this->mediaVariants($media),
                'alt' => $media->alt,
            ];
        }

        return $screenshots;
    }

    /**
     * Get the resource object's `self` link.
     */
    protected function selfLink(): string
    {
        return route('api.v1.projects.show', ['project' => $this->model()->getKey()]);
    }
}
