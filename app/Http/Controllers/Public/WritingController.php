<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Writing;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WritingController extends Controller
{
    /**
     * The page size §3.6 fixes for the writing list.
     */
    private const PER_PAGE = 10;

    /**
     * Show the published writings, newest first (§3.6).
     *
     * The list never carries the ad unit: Google Ads is scoped to
     * `/writing/{writing:slug}` alone (§8.5), and this page renders no ad script
     * of any kind.
     */
    public function index(): Response
    {
        $query = ContentQuery::published(Writing::class)
            ->with(['tags', 'series'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        /** @var LengthAwarePaginator<int, Writing> $paginator */
        $paginator = ContentQuery::paginate($query, self::PER_PAGE);

        $description = $this->description($paginator->getCollection());
        $canonical = $this->canonical($paginator->currentPage());

        $jsonLd = StructuredData::graph([
            ...StructuredData::base(),
            ...StructuredData::collectionPage(
                'Writing',
                $canonical,
                StructuredData::items($paginator->getCollection()),
                $description,
            ),
            ...StructuredData::breadcrumbs($this->breadcrumbs()),
        ]);

        return Inertia::render('public/Writing', [
            'writings' => $paginator->through(fn (Writing $writing): array => $this->card($writing)),
            'title' => $this->title(),
            'description' => $description,
            'canonical' => $canonical,
            'noindex' => $this->noindex($paginator->currentPage()),
            ...$this->robots($paginator->currentPage()),
            'jsonLd' => $jsonLd,
        ]);
    }

    /**
     * Show one published writing by slug (§3.2).
     *
     * The lookup is explicit rather than implicit route-model binding: binding
     * resolves `{writing:slug}` through a plain `where`, which would happily
     * return a draft, and §3.1 item 4 makes a draft a 404, never a 403. The
     * `published()` scope is the only place that rule is written down, so the
     * binding is done with it.
     */
    public function show(string $writing): Response
    {
        /** @var Writing $writing */
        $writing = ContentQuery::published(Writing::class)
            ->with(['tags', 'series', 'media'])
            ->where('slug', $writing)
            ->firstOrFail();

        $description = $this->metaDescription($writing);
        $canonical = StructuredData::url('writing.show', $writing);

        $jsonLd = StructuredData::graph([
            ...StructuredData::base(),
            ...StructuredData::blogPosting($writing),
            ...StructuredData::breadcrumbs([
                ...$this->breadcrumbs(),
                ['title' => $writing->title, 'href' => $canonical],
            ]),
        ]);

        return Inertia::render('public/WritingShow', [
            'writing' => [
                'title' => $writing->title,
                'slug' => $writing->slug,
                'excerpt' => $writing->excerpt,
                'bodyHtml' => $writing->body_html,
                'publishedAt' => $writing->published_at?->toIso8601String(),
                'publishedLabel' => $writing->published_at?->format('j F Y') ?? '',
                'updatedAt' => $writing->updated_at?->toIso8601String(),
                'readingTime' => $writing->reading_time_minutes,
                'series' => $writing->series?->title,
                'seriesSlug' => $writing->series?->slug,
                'tags' => $this->tags($writing),
                'isPlaceholder' => $writing->is_placeholder,
                'image' => $writing->og_image_path,
                'coverVariants' => $this->coverVariants($writing),
            ],
            'title' => $this->title($writing->title),
            'description' => $description,
            'canonical' => $canonical,
            'noindex' => $this->noindex(),
            'jsonLd' => $jsonLd,
        ]);
    }

    /**
     * The writing's processed cover variants, keyed by variant name (§9.10).
     *
     * The same shape as the API's `cover_variants`, so the detail page can
     * prefer the `1200×630` `og_image` size when one exists while
     * `og_image_path` stays the client-supplied string the API's write contract
     * pins (pinned decision 16).
     *
     * @return array<string, array{url: string, width: int, height: int, mime: string}>|null
     */
    private function coverVariants(Writing $writing): ?array
    {
        $cover = $writing->media()
            ->where('collection', 'cover')
            ->where('status', 'processed')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($cover === null || ! is_array($cover->variants)) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $stored */
        $stored = $cover->variants;

        $variants = [];

        foreach ($stored as $key => $variant) {
            $variants[$key] = [
                'url' => route('media.show', ['media' => $cover->getKey(), 'variant' => $key]),
                'width' => (int) ($variant['width'] ?? 0),
                'height' => (int) ($variant['height'] ?? 0),
                'mime' => (string) ($variant['mime'] ?? ''),
            ];
        }

        return $variants;
    }

    /**
     * Shape one writing into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function card(Writing $writing): array
    {
        $meta = [];

        if ($writing->published_at !== null) {
            $meta[] = ['label' => 'Published', 'value' => $writing->published_at->format('j F Y')];
        }

        if ($writing->reading_time_minutes !== null) {
            $meta[] = ['label' => 'Reading time', 'value' => $writing->reading_time_minutes.' min read'];
        }

        if ($writing->series !== null) {
            $meta[] = ['label' => 'Series', 'value' => $writing->series->title];
        }

        return [
            'id' => $writing->id,
            'title' => $writing->title,
            'summary' => $writing->excerpt ?? $writing->summary,
            'href' => route('writing.show', $writing, false),
            'meta' => $meta,
            'tags' => $this->tags($writing),
            'type' => ContentType::Writing->value,
            'isPlaceholder' => $writing->is_placeholder,
            'slug' => $writing->slug,
            'publishedAt' => $writing->published_at?->toIso8601String(),
            'readingTime' => $writing->reading_time_minutes,
        ];
    }

    /**
     * Map the attached tags to the shape the public card renders.
     *
     * @return list<array{name: string, slug: string}>
     */
    private function tags(Writing $writing): array
    {
        return array_values($writing->tags
            ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
            ->all());
    }

    /**
     * Build the §3.9 meta description: `Notes on {top three tags}.`
     *
     * @param  Collection<int, Writing>  $writings
     */
    private function description(Collection $writings): string
    {
        $tags = $writings
            ->flatMap(fn (Writing $writing): array => $writing->tags->pluck('name')->all())
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(3)
            ->all();

        if ($tags === []) {
            return 'Notes, walkthroughs and post-mortems.';
        }

        return 'Notes on '.$this->readableList($tags).'.';
    }

    /**
     * The writing detail description (§3.9, §2.4 item 5).
     *
     * `meta_description` wins when it is set; otherwise the excerpt, truncated
     * to 155 characters. The title is the last resort, because §3.9 requires a
     * non-empty description on every route.
     */
    private function metaDescription(Writing $writing): string
    {
        $candidates = [$writing->meta_description, $writing->excerpt, $writing->summary, $writing->title];

        foreach ($candidates as $candidate) {
            $value = Str::squish(strip_tags((string) $candidate));

            if ($value !== '') {
                return Str::limit($value, 155, '');
            }
        }

        return $writing->title;
    }

    /**
     * `a, b and c` — the one list style used in the composed descriptions.
     *
     * @param  array<int, string>  $values
     */
    private function readableList(array $values): string
    {
        $last = array_pop($values);

        return $values === [] ? (string) $last : implode(', ', $values).' and '.$last;
    }

    /**
     * Page 1 canonicalises to the clean URL; page ≥ 2 is self-canonical (§3.1 item 6).
     */
    private function canonical(int $page): string
    {
        $url = StructuredData::url('writing.index');

        return $page > 1 ? $url.'?page='.$page : $url;
    }

    /**
     * `{Section} - {Name}`, the §3.9 pattern for the section list.
     */
    private function title(?string $subject = null): string
    {
        return ($subject ?? 'Writing').' - '.(string) config('app.name');
    }

    /**
     * Nothing is indexable while the content is placeholder; page ≥ 2 of a
     * paginated list is `noindex,follow` (§3.1 items 5 and 6).
     */
    private function noindex(int $page = 1): bool
    {
        return ! config('app.indexable') || $page > 1;
    }

    /**
     * The explicit robots directive page ≥ 2 needs (§3.1 item 6).
     *
     * Page 1 sends no prop at all, so `SeoHead.vue`'s boolean rule governs; page
     * ≥ 2 is `noindex,follow`, which the single `noindex` boolean cannot express.
     * §3.1 item 5 outranks item 6: on a non-indexable site page ≥ 2 also sends no
     * prop, so the boolean rule renders the site-wide `noindex,nofollow`.
     *
     * @return array{robots?: string}
     */
    private function robots(int $page): array
    {
        return $page > 1 && config('app.indexable') ? ['robots' => 'noindex,follow'] : [];
    }

    /**
     * The `Home / Writing` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Writing', 'href' => StructuredData::url('writing.index')],
        ];
    }
}
