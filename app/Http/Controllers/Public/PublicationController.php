<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Publication;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PublicationController extends Controller
{
    /**
     * The page size §3.6 fixes for the publications list.
     */
    private const PER_PAGE = 12;

    /**
     * Show the published publications, most recently issued first (§3.6).
     */
    public function index(): Response
    {
        $query = ContentQuery::published(Publication::class)
            ->with('tags')
            ->orderByDesc('issued_on')
            ->orderByDesc('id');

        /** @var LengthAwarePaginator<int, Publication> $paginator */
        $paginator = ContentQuery::paginate($query, self::PER_PAGE);

        $description = $this->description($paginator->total());

        $jsonLd = StructuredData::graph([
            ...StructuredData::base(),
            ...StructuredData::collectionPage(
                'Publications',
                StructuredData::url('publications.index'),
                StructuredData::items($paginator->getCollection()),
                $description,
            ),
            ...StructuredData::breadcrumbs($this->breadcrumbs()),
        ]);

        $paginator = $paginator->through(fn (Publication $publication): array => $this->card($publication));

        return Inertia::render('public/Publications', [
            'publications' => $paginator,
            'title' => 'Publications - '.config('app.name'),
            'description' => $description,
            'canonical' => $this->canonical($paginator->currentPage()),
            'jsonLd' => $jsonLd,
            'noindex' => ! config('app.indexable') || $paginator->currentPage() > 1,
            ...$this->robots($paginator->currentPage()),
        ]);
    }

    /**
     * Build the canonical URL: page one is the clean URL, later pages are
     * self-canonical (§3.1 item 6).
     */
    private function canonical(int $page): string
    {
        $url = StructuredData::url('publications.index');

        return $page > 1 ? $url.'?page='.$page : $url;
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
     * Shape one publication into the item the public card renders.
     *
     * `doi_url` is the accessor the model exposes over the stored DOI (§2.2), so
     * a publication without a DOI simply has no outbound link.
     *
     * @return array<string, mixed>
     */
    private function card(Publication $publication): array
    {
        $meta = [
            ['label' => 'Venue', 'value' => $publication->venue],
            ['label' => 'Published', 'value' => $publication->issued_on->format('F Y')],
        ];

        if ($publication->authors !== null) {
            $meta[] = ['label' => 'Authors', 'value' => $publication->authors];
        }

        if ($publication->doi !== null) {
            $meta[] = ['label' => 'DOI', 'value' => $publication->doi];
        }

        return [
            'id' => $publication->id,
            'title' => $publication->title,
            'summary' => $publication->summary,
            'href' => $publication->doi_url,
            'meta' => $meta,
            'tags' => $this->tags($publication),
            'type' => ContentType::Publication->value,
            'isPlaceholder' => $publication->is_placeholder,
            'venue' => $publication->venue,
            'authors' => $publication->authors,
            'doi' => $publication->doi,
            'doiUrl' => $publication->doi_url,
            'issuedOn' => $publication->issued_on->toDateString(),
        ];
    }

    /**
     * Map the attached tags to the shape the public card renders.
     *
     * @return array<int, array{name: string, slug: string}>
     */
    private function tags(Publication $publication): array
    {
        return $publication->tags
            ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
            ->values()
            ->all();
    }

    /**
     * Build the §3.9 meta description: `{n} peer-reviewed publications.`
     */
    private function description(int $count): string
    {
        if ($count === 0) {
            return 'Peer-reviewed publications.';
        }

        return "{$count} peer-reviewed ".Str::plural('publication', $count).'.';
    }

    /**
     * The `Home / Publications` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Publications', 'href' => StructuredData::url('publications.index')],
        ];
    }
}
