<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WorkExperience;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The experience timeline (§3.6).
 *
 * `/experience` is a list without a detail page: every entry renders complete in
 * the timeline and again on `/cv`, so the row is the whole record.
 */
class WorkExperienceController extends Controller
{
    /**
     * The page size §3.6 fixes for the experience list.
     */
    private const PER_PAGE = 12;

    /**
     * Show the published roles, open-ended ones first (§2.3).
     *
     * The ordering is the portable form of `ORDER BY end_date IS NULL DESC,
     * end_date DESC`: a `CASE` expression sorts identically on SQLite and
     * MariaDB, where `(end_date IS NULL)` does not. The `id` tiebreaker keeps
     * pagination deterministic (§2.9 item 9).
     */
    public function index(): Response
    {
        $query = ContentQuery::published(WorkExperience::class)
            ->with('tags')
            ->orderByRaw('CASE WHEN end_date IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('end_date')
            ->orderByDesc('id');

        /** @var LengthAwarePaginator<int, WorkExperience> $paginator */
        $paginator = ContentQuery::paginate($query, self::PER_PAGE);

        $description = $this->description($paginator->getCollection(), $paginator->total());

        $jsonLd = StructuredData::graph([
            ...StructuredData::base(),
            ...StructuredData::collectionPage(
                'Experience',
                StructuredData::url('experience.index'),
                StructuredData::items($paginator->getCollection()),
                $description,
            ),
            ...StructuredData::breadcrumbs($this->breadcrumbs()),
        ]);

        $paginator->through(fn (WorkExperience $experience): array => $this->entry($experience));

        return Inertia::render('public/Experience', [
            'experiences' => $paginator,
            'title' => 'Experience - '.config('app.name'),
            'description' => $description,
            'canonical' => $this->canonical($paginator->currentPage()),
            'jsonLd' => $jsonLd,
            'noindex' => ! config('app.indexable') || $paginator->currentPage() > 1,
            ...$this->robots($paginator->currentPage()),
        ]);
    }

    /**
     * Shape one role into the timeline entry the page renders.
     *
     * A null `end_date` stays null rather than becoming a string: the page
     * renders it as "Present", and the JSON-LD builder reads the same field.
     *
     * @return array<string, mixed>
     */
    private function entry(WorkExperience $experience): array
    {
        return [
            'id' => $experience->id,
            'title' => $experience->title,
            'employer' => $experience->employer,
            'employerUrl' => $experience->employer_url,
            'location' => $experience->location,
            'startDate' => $experience->start_date->toDateString(),
            'endDate' => $experience->end_date?->toDateString(),
            'summary' => $experience->summary,
            'highlights' => $experience->highlights ?? [],
            'tags' => $this->tags($experience),
            'isPlaceholder' => $experience->is_placeholder,
        ];
    }

    /**
     * Map the attached tags to the shape the public chip renders.
     *
     * @return list<array{name: string, slug: string}>
     */
    private function tags(WorkExperience $experience): array
    {
        return array_values(
            $experience->tags
                ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
                ->all(),
        );
    }

    /**
     * Build the §3.9 meta description: `{n} roles, most recently {title} at {employer}.`
     *
     * The count is the whole result set, not the current page, so page two does
     * not claim a smaller career.
     *
     * @param  Collection<int, WorkExperience>  $experiences
     */
    private function description(Collection $experiences, int $total): string
    {
        $current = $experiences->first();

        if ($total === 0 || $current === null) {
            return 'Roles and responsibilities.';
        }

        return "{$total} ".Str::plural('role', $total).", most recently {$current->title} at {$current->employer}.";
    }

    /**
     * Build the canonical URL: page one is the clean URL, later pages are
     * self-canonical (§3.1 item 6).
     */
    private function canonical(int $page): string
    {
        $url = StructuredData::url('experience.index');

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
     * The `Home / Experience` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Experience', 'href' => StructuredData::url('experience.index')],
        ];
    }
}
