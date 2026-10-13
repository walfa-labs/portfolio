<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    /**
     * The page size §3.7 fixes for a tag page — across the whole page, not per group.
     */
    private const PER_PAGE = 24;

    /**
     * Show every tag with its published item count (§3.7).
     *
     * Counts come from {@see ContentQuery::publishedTagCounts()} — one grouped
     * query per content type, never one per tag, merged in PHP because the pivot
     * points at seven different tables — so this page and the home skills cloud
     * aggregate identically.
     */
    public function index(): Response
    {
        $counts = ContentQuery::publishedTagCounts();

        $tags = Tag::query()
            ->get()
            ->map(fn (Tag $tag): array => [
                'name' => $tag->name,
                'slug' => $tag->slug,
                'count' => $counts[$tag->id] ?? 0,
                'isPlaceholder' => $tag->is_placeholder,
            ])
            ->sort(fn (array $a, array $b): int => $b['count'] <=> $a['count']
                ?: strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])))
            ->values()
            ->all();

        $description = $this->indexDescription($tags);

        return Inertia::render('public/Tags', [
            'tags' => $tags,
            'title' => 'Tags - '.(string) config('app.name'),
            'description' => $description,
            'canonical' => StructuredData::url('tags.index'),
            'noindex' => $this->noindex(),
            // §3.9 gives `/tags` no `CollectionPage`: `StructuredData::items()` only
            // wraps content models, and a tag is not one.
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::breadcrumbs($this->breadcrumbs()),
            ]),
        ]);
    }

    /**
     * Show every published item carrying one tag, grouped by content type (§3.7).
     *
     * The grouping happens in the page: the controller keeps the fixed type order
     * §3.7 requires and paginates that single ordered sequence, so a page of 24
     * is 24 items across every group rather than 24 per group.
     */
    public function show(string $tag): Response
    {
        /** @var Tag $tag */
        $tag = Tag::query()->where('slug', $tag)->firstOrFail();

        $entries = $this->entries($tag);
        $total = count($entries);
        $page = max(1, Paginator::resolveCurrentPage());
        $slice = array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $paginator = new LengthAwarePaginator(
            array_map(fn (array $entry): array => $this->card($entry['type'], $entry['model']), $slice),
            $total,
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query()],
        );

        $description = $this->description($tag, $entries);
        $canonical = $this->canonical($tag, $page);
        $handle = '#'.$tag->slug;

        $jsonLd = StructuredData::graph([
            ...StructuredData::base(),
            ...StructuredData::collectionPage(
                $handle,
                $canonical,
                StructuredData::items(array_column($slice, 'model')),
                $description,
            ),
            ...StructuredData::breadcrumbs([
                ...$this->breadcrumbs(),
                ['title' => $handle, 'href' => $canonical],
            ]),
        ]);

        return Inertia::render('public/Tag', [
            'tag' => [
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'isPlaceholder' => $tag->is_placeholder,
            ],
            'items' => $paginator,
            'title' => $handle.' - '.(string) config('app.name'),
            'description' => $description,
            'canonical' => $canonical,
            'noindex' => $this->noindex($page),
            ...$this->robots($page),
            'jsonLd' => $jsonLd,
        ]);
    }

    /**
     * Every published item carrying the tag, in the fixed §3.7 group order.
     *
     * The order is {@see ContentType}'s declaration order: experience →
     * education → certifications → publications → projects → awards → writing.
     *
     * @return list<array{type: ContentType, model: Model}>
     */
    private function entries(Tag $tag): array
    {
        $entries = [];

        foreach (ContentType::cases() as $type) {
            foreach ($this->query($type, $tag)->get() as $model) {
                $entries[] = ['type' => $type, 'model' => $model];
            }
        }

        return $entries;
    }

    /**
     * The published records of one type carrying the tag, in that type's own order.
     *
     * @return Builder<Model>
     */
    private function query(ContentType $type, Tag $tag): Builder
    {
        $query = ContentQuery::published($type->modelClass())
            ->with('tags')
            ->whereHas('tags', fn (Builder $tags): Builder => $tags->where('tags.id', $tag->id));

        // The open-ended role sorts first, and the expression is written so it is
        // portable across SQLite and MariaDB (§2.9, §3.6).
        return match ($type) {
            ContentType::WorkExperience => $query
                ->orderByRaw('CASE WHEN end_date IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('end_date')
                ->orderByDesc('id'),
            ContentType::Education => $query->orderByDesc('end_date')->orderByDesc('id'),
            ContentType::Certification => $query->orderByDesc('issued_on')->orderByDesc('id'),
            ContentType::Publication => $query->orderByDesc('issued_on')->orderByDesc('id'),
            ContentType::Project => $query->orderByDesc('started_on')->orderByDesc('id'),
            ContentType::Award => $query->orderByDesc('awarded_at')->orderByDesc('id'),
            ContentType::Writing => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * Shape one tagged record into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function card(ContentType $type, Model $model): array
    {
        return [
            'id' => (int) $model->getKey(),
            'title' => (string) $model->getAttribute('title'),
            'summary' => $model->getAttribute('summary'),
            'href' => $this->href($model),
            'meta' => $this->meta($model),
            'tags' => $this->tags($model),
            'type' => $type->value,
            'isPlaceholder' => (bool) $model->getAttribute('is_placeholder'),
        ];
    }

    /**
     * The canonical artefact of a record, or the page that renders it.
     *
     * Only `writing` has a detail page (§3.6): for the other six the meaningful
     * artefact lives at the issuer, the DOI or the repository, so the card links
     * out and the tag page keeps one canonical URL per thing.
     */
    private function href(Model $model): ?string
    {
        return match (true) {
            $model instanceof Writing => route('writing.show', $model, false),
            $model instanceof Certification => $model->credential_url,
            $model instanceof Publication => $model->doi_url,
            $model instanceof Project => $model->live_url ?? $model->repo_url,
            $model instanceof WorkExperience => $model->employer_url,
            $model instanceof Education => $model->institution_url,
            default => null,
        };
    }

    /**
     * The per-type metadata row the card renders under its title.
     *
     * @return list<array{label: string, value: string}>
     */
    private function meta(Model $model): array
    {
        return match (true) {
            $model instanceof WorkExperience => [
                ['label' => 'Employer', 'value' => $model->employer],
                ['label' => 'Dates', 'value' => $this->dates($model->start_date, $model->end_date)],
            ],
            $model instanceof Education => [
                ['label' => 'Institution', 'value' => $model->institution],
                ['label' => 'Dates', 'value' => $this->dates($model->start_date, $model->end_date)],
            ],
            $model instanceof Certification => [
                ['label' => 'Issuer', 'value' => $model->issuer],
                ['label' => 'Issued', 'value' => $model->issued_on->format('j F Y')],
            ],
            $model instanceof Publication => [
                ['label' => 'Venue', 'value' => $model->venue],
                ['label' => 'Issued', 'value' => $model->issued_on->format('j F Y')],
            ],
            $model instanceof Project => $this->projectMeta($model),
            $model instanceof Award => [
                ['label' => 'Awarded by', 'value' => $model->awarding_body],
                ['label' => 'Awarded', 'value' => $model->awarded_at->format('F Y')],
            ],
            $model instanceof Writing => $this->writingMeta($model),
            default => [],
        };
    }

    /**
     * A project's dates, skipping the halves that are not set.
     *
     * @return list<array{label: string, value: string}>
     */
    private function projectMeta(Project $project): array
    {
        $meta = [];

        if ($project->started_on !== null) {
            $meta[] = ['label' => 'Started', 'value' => $project->started_on->format('F Y')];
        }

        if ($project->ended_on !== null) {
            $meta[] = ['label' => 'Ended', 'value' => $project->ended_on->format('F Y')];
        }

        return $meta;
    }

    /**
     * A writing's publication date and reading time, skipping what is not set.
     *
     * @return list<array{label: string, value: string}>
     */
    private function writingMeta(Writing $writing): array
    {
        $meta = [];

        if ($writing->published_at !== null) {
            $meta[] = ['label' => 'Published', 'value' => $writing->published_at->format('j F Y')];
        }

        if ($writing->reading_time_minutes !== null) {
            $meta[] = ['label' => 'Reading time', 'value' => $writing->reading_time_minutes.' min read'];
        }

        return $meta;
    }

    /**
     * Map the attached tags to the shape the public card renders.
     *
     * @return list<array{name: string, slug: string}>
     */
    private function tags(Model $model): array
    {
        /** @var iterable<int, Tag> $tags */
        $tags = $model->getRelation('tags');

        $mapped = [];

        foreach ($tags as $tag) {
            $mapped[] = ['name' => $tag->name, 'slug' => $tag->slug];
        }

        return $mapped;
    }

    /**
     * `Jan 2020 — Present` / `Jan 2020 — Mar 2022`.
     *
     * Typed on `DateTimeInterface` rather than the concrete Carbon class the
     * casts return, so the signature cannot break if the date factory changes.
     */
    private function dates(?DateTimeInterface $start, ?DateTimeInterface $end): string
    {
        $from = $start?->format('M Y') ?? 'Undated';

        return $from.' — '.($end?->format('M Y') ?? 'Present');
    }

    /**
     * Build the §3.9 meta description: `{n} items tagged #{slug} across …`
     *
     * @param  list<array{type: ContentType, model: Model}>  $entries
     */
    private function description(Tag $tag, array $entries): string
    {
        $total = count($entries);

        if ($total === 0) {
            return 'Nothing is tagged #'.$tag->slug.' yet.';
        }

        $types = [];

        foreach ($entries as $entry) {
            $types[$entry['type']->value] = Str::plural(Str::lower($entry['type']->label()));
        }

        return $total.' '.Str::plural('item', $total).' tagged #'.$tag->slug
            .' across '.$this->readableList(array_values($types)).'.';
    }

    /**
     * Build the §3.9 meta description for `/tags`: `Browse all {n} topics.`
     *
     * @param  array<int, array<string, mixed>>  $tags
     */
    private function indexDescription(array $tags): string
    {
        $count = count($tags);

        return $count === 0
            ? 'Browse every topic.'
            : 'Browse all '.$count.' '.Str::plural('topic', $count).'.';
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
    private function canonical(Tag $tag, int $page): string
    {
        $url = StructuredData::url('tags.show', $tag);

        return $page > 1 ? $url.'?page='.$page : $url;
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
     * The `Home / Tags` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Tags', 'href' => StructuredData::url('tags.index')],
        ];
    }
}
