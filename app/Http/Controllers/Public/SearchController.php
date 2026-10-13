<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Support\Search\ContentSearcher;
use App\Support\Seo\StructuredData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SearchController extends Controller
{
    /**
     * The page size pinned for the public search page (§3.2, §3.8).
     */
    private const PER_PAGE = ContentSearcher::DEFAULT_PER_PAGE;

    /**
     * Run the site-wide search (§3.8).
     *
     * `q` shorter than two characters is not a `422` and not a redirect: the page
     * still answers `200`, with `results === null` and a message explaining what
     * was rejected. An absent `q` is a fresh search page, not a rejection.
     *
     * The search page is `noindex,follow` and additionally carries the
     * `X-Robots-Tag` header §3.9 specifies for `/search`.
     */
    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $types = $this->types((string) $request->query('type', ''));

        $results = null;
        $links = [];
        $meta = null;
        $message = null;

        if ($q !== '') {
            if (mb_strlen($q) < ContentSearcher::MIN_QUERY_LENGTH) {
                $message = 'Enter at least '.ContentSearcher::MIN_QUERY_LENGTH.' characters to search.';
            } else {
                $paginator = ContentSearcher::search(
                    Str::limit($q, ContentSearcher::MAX_QUERY_LENGTH, ''),
                    $types,
                    self::PER_PAGE,
                );

                $results = array_map(fn (array $hit): array => $this->card($hit), $paginator->items());
                $links = $paginator->linkCollection()->toArray();
                $meta = [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ];
            }
        }

        $description = 'Search this site.';
        $canonical = StructuredData::url('search');

        $jsonLd = StructuredData::graph([
            ...StructuredData::base(),
            ...StructuredData::page('SearchResultsPage', $canonical, 'Search', $description),
            ...StructuredData::breadcrumbs([
                ['title' => 'Home', 'href' => StructuredData::url('home')],
                ['title' => 'Search', 'href' => $canonical],
            ]),
        ]);

        $response = Inertia::render('public/Search', [
            'q' => $q,
            'types' => array_map(fn (ContentType $type): string => $type->value, $types),
            'results' => $results,
            'links' => $links,
            'meta' => $meta,
            'message' => $message,
            'title' => 'Search - '.(string) config('app.name'),
            'description' => $description,
            'canonical' => $canonical,
            'noindex' => true,
            ...$this->robots(),
            'jsonLd' => $jsonLd,
        ])->toResponse($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * The explicit robots directive the search page needs (§3.8).
     *
     * §3.8 makes the search page `noindex,follow` while §3.9 pins its
     * `X-Robots-Tag` header to `noindex, nofollow` — the PRD contradicts itself,
     * and both halves stay as written. §3.1 item 5 outranks §3.8: on a
     * non-indexable site no prop is sent, so `SeoHead.vue`'s boolean rule
     * renders the site-wide `noindex,nofollow` over the always-present
     * `noindex` boolean.
     *
     * @return array{robots?: string}
     */
    private function robots(): array
    {
        return config('app.indexable') ? ['robots' => 'noindex,follow'] : [];
    }

    /**
     * Read the singular, comma-separated `type` filter.
     *
     * Unknown values are ignored rather than rejected: a hand-edited URL should
     * widen the search, not fail it. An empty result means "every type".
     *
     * @return list<ContentType>
     */
    private function types(string $raw): array
    {
        $types = [];

        foreach (explode(',', $raw) as $value) {
            $type = ContentType::tryFrom(trim($value));

            if ($type !== null && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Shape one search hit into the item the public card renders.
     *
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function card(array $hit): array
    {
        $meta = [];
        $publishedAt = $hit['published_at'] ?? null;

        if (is_string($publishedAt) && $publishedAt !== '') {
            $meta[] = ['label' => 'Published', 'value' => CarbonImmutable::parse($publishedAt)->format('j F Y')];
        }

        return [
            'id' => $hit['id'] ?? 0,
            'title' => (string) ($hit['title'] ?? ''),
            'summary' => $hit['excerpt'] ?? null,
            'href' => (string) ($hit['path'] ?? '/'),
            'meta' => $meta,
            'tags' => $hit['tags'] ?? [],
            'type' => $hit['type'] ?? null,
            'isPlaceholder' => (bool) ($hit['is_placeholder'] ?? false),
            'score' => $hit['score'] ?? 0.0,
        ];
    }
}
