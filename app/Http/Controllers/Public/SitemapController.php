<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\Writing;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The XML sitemap (§3.9).
 *
 * The document is built once an hour and cached; content observers flush the
 * entry when a row is saved. When `config('app.indexable')` is false the sitemap
 * collapses to an empty `<urlset>`: `robots.txt` is telling crawlers to stay
 * away, so listing URLs would be incoherent (§18).
 */
class SitemapController extends Controller
{
    /**
     * The cache key and lifetime of the rendered document.
     *
     * The key is public so the content observers registered at boot can forget
     * exactly this entry without re-deriving its name.
     */
    public const CACHE_KEY = 'sitemap.xml';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * The static public routes, with the priorities §3.9 assigns them.
     *
     * @var list<array{name: string, priority: string}>
     */
    private const STATIC_ROUTES = [
        ['name' => 'home', 'priority' => '1.0'],
        ['name' => 'cv', 'priority' => '0.9'],
        ['name' => 'experience.index', 'priority' => '0.8'],
        ['name' => 'education.index', 'priority' => '0.8'],
        ['name' => 'certifications.index', 'priority' => '0.8'],
        ['name' => 'publications.index', 'priority' => '0.8'],
        ['name' => 'projects.index', 'priority' => '0.8'],
        ['name' => 'awards.index', 'priority' => '0.8'],
        ['name' => 'writing.index', 'priority' => '0.8'],
        ['name' => 'tags.index', 'priority' => '0.5'],
        ['name' => 'search', 'priority' => '0.5'],
        ['name' => 'contact.show', 'priority' => '0.5'],
        ['name' => 'privacy', 'priority' => '0.5'],
    ];

    /**
     * Render the sitemap.
     */
    public function __invoke(): Response
    {
        $xml = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): string => view('sitemap', ['urls' => $this->urls()])->render(),
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Build every URL the sitemap may list.
     *
     * @return list<array{loc: string, priority: string, lastmod: string|null}>
     */
    private function urls(): array
    {
        if (! config('app.indexable')) {
            return [];
        }

        $urls = [];

        foreach (self::STATIC_ROUTES as $route) {
            $urls[] = [
                'loc' => StructuredData::url($route['name']),
                'priority' => $route['priority'],
                'lastmod' => null,
            ];
        }

        /** @var Collection<int, Writing> $writings */
        $writings = ContentQuery::published(Writing::class)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        foreach ($writings as $writing) {
            $urls[] = [
                'loc' => StructuredData::url('writing.show', $writing),
                'priority' => '0.7',
                'lastmod' => $writing->updated_at?->toAtomString(),
            ];
        }

        foreach ($this->publishedTags() as $tag) {
            $urls[] = [
                'loc' => StructuredData::url('tags.show', $tag),
                'priority' => '0.3',
                'lastmod' => null,
            ];
        }

        return $urls;
    }

    /**
     * Get every tag carried by at least one published item, ordered by name.
     *
     * A tag with no published items has no content to show, so listing its URL
     * would be a crawl of an empty page.
     *
     * @return Collection<int, Tag>
     */
    private function publishedTags(): Collection
    {
        $tagIds = [];

        foreach (ContentType::cases() as $type) {
            $taggableIds = ContentQuery::published($type->modelClass())->pluck('id');

            if ($taggableIds->isEmpty()) {
                continue;
            }

            $typeTagIds = Taggable::query()
                ->where('taggable_type', $type->value)
                ->whereIn('taggable_id', $taggableIds)
                ->pluck('tag_id');

            foreach ($typeTagIds as $tagId) {
                $tagIds[(int) $tagId] = true;
            }
        }

        if ($tagIds === []) {
            return new Collection;
        }

        return Tag::query()
            ->whereIn('id', array_keys($tagIds))
            ->orderBy('name')
            ->get();
    }
}
