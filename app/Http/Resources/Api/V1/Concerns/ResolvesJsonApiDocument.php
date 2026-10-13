<?php

namespace App\Http\Resources\Api\V1\Concerns;

use App\Enums\ContentStatus;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\TagResource;
use App\Models\Concerns\HasMedia;
use App\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The document members every v1 resource shares (§4.4).
 *
 * `JsonApiResource` derives `id` and `type`, resolves sparse fieldsets and
 * embeds the requested relationships; this trait adds the members §4.4.1 and
 * §4.4.2 show and the framework leaves to the application: the resource
 * object's `self` link, the one embeddable relationship (`tags`, §4.9), the
 * single-resource document's `links.self` and `meta.updated_at`, and the §4.7
 * pagination members through {@see ApiCollection}.
 *
 * The `tags` relationship object carries `data` (the resource linkage) and
 * nothing else. JSON:API 1.1 requires a server to answer `GET` on any
 * relationship URL it advertises as a `self` link, and v1 deliberately serves
 * no relationship endpoints (Table A is a closed set of eight resources,
 * §4.2), so emitting the links would advertise routes that do not exist.
 * `links` is optional in a relationship object, so omitting it is valid where
 * a dead `self` link is not. The resource object's own `links.self` — which
 * does resolve — is unaffected.
 */
trait ResolvesJsonApiDocument
{
    /**
     * The one `media.status` a renderable row carries (§9.2).
     */
    private const MEDIA_STATUS_PROCESSED = 'processed';

    /**
     * The variant-key prefix of the low-quality image placeholder (§9.5).
     */
    private const LQIP_PREFIX = 'lqip';

    /**
     * Get the absolute URL of the resource's own document.
     *
     * Built from the resource's named route rather than from the request, so a
     * resource's `links.self` and the `Location` header a `POST` returns are
     * the same URL (§4.5).
     */
    abstract protected function selfLink(): string;

    /**
     * Create the collection every resource is listed through.
     *
     * Laravel's default pagination members (`current_page`, `per_page`,
     * `first_page_url`, …) are not the JSON:API shape §4.4.1 documents, so the
     * collection object is the one place that replaces them (§4.7).
     */
    protected static function newCollection($resource): ApiCollection
    {
        return new ApiCollection($resource, static::class);
    }

    /**
     * Get the resource object's links.
     *
     * @return array<string, string>
     */
    public function toLinks(Request $request): array
    {
        return ['self' => $this->selfLink()];
    }

    /**
     * Get the resource's relationships.
     *
     * `tags` is the only embeddable relationship in v1 (§4.9): every content
     * resource carries it, and `series` is deliberately an attribute object on
     * `writings` rather than a relationship (§4.4.2).
     *
     * @return array<string, class-string>
     */
    public function toRelationships(Request $request): array
    {
        return ['tags' => TagResource::class];
    }

    /**
     * Get the additional document members a single resource carries.
     *
     * `meta.updated_at` is the single-resource document's validator aid
     * (§4.4.2); a collection carries `meta.page` instead and gets it from
     * {@see ApiCollection}.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function with($request): array
    {
        /** @var array<string, mixed> $members */
        $members = parent::with($request);

        $members['links'] = ['self' => $this->selfLink()];
        $members['meta'] = ['updated_at' => $this->isoTimestamp($this->model()->getAttribute('updated_at'))];

        return $members;
    }

    /**
     * Get the model behind the resource.
     */
    protected function model(): Model
    {
        /** @var Model $model */
        $model = $this->resource;

        return $model;
    }

    /**
     * Render a timestamp the way §4.4.2 does: ISO 8601 in UTC, `Z`-suffixed.
     */
    protected function isoTimestamp(?CarbonImmutable $value): ?string
    {
        return $value?->toIso8601ZuluString();
    }

    /**
     * Render a `date` column: `2026-03-01`.
     */
    protected function isoDate(?CarbonImmutable $value): ?string
    {
        return $value?->toDateString();
    }

    /**
     * Whether the record is published.
     *
     * The public API only ever returns published records (§4.8), so this is
     * `true` on every read; §4.4.2 names the member `is_published` rather than
     * exposing the `status` column the write contract takes (§4.5).
     */
    protected function isPublished(): bool
    {
        return $this->model()->getAttribute('status') === ContentStatus::Published;
    }

    /**
     * The derived media attributes a resource exposes (§4.4, §9.10).
     *
     * Every derived image attribute is read-only and carries its `alt`
     * alongside it, so a client can render accessible markup without a second
     * request. The keys are emitted as `null` by default so the document
     * contract never changes shape for a resource that has no media; a resource
     * that has some overrides this method with {@see derivedMediaValues()}.
     *
     * @return array<string, null>
     */
    protected function derivedMediaAttributes(string ...$keys): array
    {
        return array_fill_keys($keys, null);
    }

    /**
     * Overlay a resource's resolved derived values on the `null` defaults.
     *
     * An override names only the keys it can actually resolve; a key it does
     * not resolve — and a key it is not asked for — keeps the document's shape
     * (§4.4).
     *
     * @param  array<array-key, string>  $keys
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    protected function derivedMediaValues(array $keys, array $resolved): array
    {
        return array_replace(
            array_fill_keys($keys, null),
            array_intersect_key($resolved, array_fill_keys($keys, true)),
        );
    }

    /**
     * Every processed media row of one collection, in display order.
     *
     * `media` is directly queryable for exactly this reason (§9.2): the morph
     * columns are the same pair the relation uses, so the lookup is one query
     * and never depends on the model declaring the `HasMedia` trait. A loaded
     * `media` relation is filtered in memory instead, so a controller that
     * eager-loads it costs no extra query.
     *
     * `processed` is the only renderable state: `pending` and `processing` have
     * no variants yet and `failed` has none at all (§9.2, §9.5).
     *
     * @return Collection<int, Media>
     */
    protected function processedMedia(string $collection): Collection
    {
        $model = $this->model();

        if ($model->relationLoaded('media')) {
            /** @var Collection<int, Media> $loaded */
            $loaded = $model->getRelation('media');

            return $loaded
                ->where('collection', $collection)
                ->where('status', self::MEDIA_STATUS_PROCESSED)
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->values();
        }

        return Media::query()
            ->where('mediable_type', $model->getMorphClass())
            ->where('mediable_id', $model->getKey())
            ->where('collection', $collection)
            ->where('status', self::MEDIA_STATUS_PROCESSED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * A media row's variants, keyed by variant name, with every URL resolved
     * through the app-mediated serving route (§9.7).
     *
     * The keys are the ones `config/media.php` generates and embed both size and
     * format — `cover_800x450.webp` — which is exactly the `{variant}` segment
     * the route takes. Width and height ride along so a client can reserve the
     * box before the bytes arrive (§9.7).
     *
     * @return array<string, array{url: string, width: int, height: int, mime: string}>
     */
    protected function mediaVariants(Media $media): array
    {
        /** @var array<string, array<string, mixed>> $stored */
        $stored = is_array($media->variants) ? $media->variants : [];

        $variants = [];

        foreach ($stored as $key => $variant) {
            $variants[$key] = [
                'url' => $this->mediaUrl($media, $key),
                'width' => (int) ($variant['width'] ?? 0),
                'height' => (int) ($variant['height'] ?? 0),
                'mime' => (string) ($variant['mime'] ?? ''),
            ];
        }

        return $variants;
    }

    /**
     * The single URL a `*_url` attribute carries.
     *
     * A lone URL has no `srcset` around it — it is an `og:image`, an API
     * client's `<img src>` — so it must be the largest size at the most broadly
     * compatible format: the §9.5 fallback encoding (JPEG, or PNG when the
     * source has transparency) wins over WebP and AVIF. The LQIP is a 32px blur
     * and never the answer to "what is this image" (§9.5).
     *
     * A collection with no variant matrix at all — `pdf`, `cv` — and an SVG
     * `logo`, which is stored sanitised as-is rather than rasterised, fall back
     * to the row's own object (§9.4, §9.5).
     */
    protected function primaryMediaUrl(Media $media): string
    {
        $best = null;

        foreach ($this->mediaVariants($media) as $key => $variant) {
            if (str_starts_with($key, self::LQIP_PREFIX)) {
                continue;
            }

            if ($best === null || $this->preferredOver($variant, $best)) {
                $best = $variant;
            }
        }

        return $best['url'] ?? $this->originalMediaUrl($media);
    }

    /**
     * The served URL of a media row's own object.
     *
     * §9.4 names every original `original.{ext}`, and a collection that is
     * stored rather than rasterised — a PDF, a CV, an SVG logo — has no variant
     * key of its own, so that basename is the key the serving route resolves
     * against the row's `path` (§9.5).
     */
    protected function originalMediaUrl(Media $media): string
    {
        $extension = pathinfo($media->path, PATHINFO_EXTENSION);

        return $this->mediaUrl($media, $extension === '' ? 'original' : 'original.'.$extension);
    }

    /**
     * The absolute URL one variant of one media row is served from (§9.7).
     */
    protected function mediaUrl(Media $media, string $variant): string
    {
        return route('media.show', ['media' => $media->getKey(), 'variant' => $variant]);
    }

    /**
     * Whether one variant is the better lone URL than another.
     *
     * The larger raster wins; a tie in width goes to the more compatible
     * format, because two encodings of one size differ only in decodability.
     *
     * @param  array{url: string, width: int, height: int, mime: string}  $candidate
     * @param  array{url: string, width: int, height: int, mime: string}  $current
     */
    private function preferredOver(array $candidate, array $current): bool
    {
        if ($candidate['width'] !== $current['width']) {
            return $candidate['width'] > $current['width'];
        }

        return self::formatRank($candidate['mime']) < self::formatRank($current['mime']);
    }

    /**
     * §9.5's compatibility order: the fallback encoding first, then WebP, then
     * AVIF. Anything else — an SVG, which has no raster equivalent — ranks last.
     */
    private static function formatRank(string $mime): int
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 0,
            'image/png' => 1,
            'image/webp' => 2,
            'image/avif' => 3,
            default => 4,
        };
    }
}
