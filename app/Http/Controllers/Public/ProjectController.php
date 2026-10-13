<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * The page size §3.6 fixes for the projects list.
     */
    private const PER_PAGE = 12;

    /**
     * Show the published projects, most recently started first (§3.6).
     */
    public function index(): Response
    {
        $query = ContentQuery::published(Project::class)
            ->with('tags')
            ->orderByDesc('started_on')
            ->orderByDesc('id');

        /** @var LengthAwarePaginator<int, Project> $paginator */
        $paginator = ContentQuery::paginate($query, self::PER_PAGE);

        $description = $this->description($paginator->getCollection());

        $jsonLd = StructuredData::graph([
            ...StructuredData::base(),
            ...StructuredData::collectionPage(
                'Projects',
                StructuredData::url('projects.index'),
                StructuredData::items($paginator->getCollection()),
                $description,
            ),
            ...StructuredData::breadcrumbs($this->breadcrumbs()),
        ]);

        $paginator = $paginator->through(fn (Project $project): array => $this->card($project));

        return Inertia::render('public/Projects', [
            'projects' => $paginator,
            'title' => 'Projects - '.config('app.name'),
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
        $url = StructuredData::url('projects.index');

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
     * Shape one project into the item the public card renders.
     *
     * The tech stack is the project's tags (§2.2), so it rides in `tags`; the
     * repository and live URLs are both outbound links, with the live site as the
     * card's primary `href` when it exists.
     *
     * @return array<string, mixed>
     */
    private function card(Project $project): array
    {
        $tags = $this->tags($project);

        $meta = [];

        if ($project->is_featured) {
            $meta[] = ['label' => 'Featured', 'value' => 'Yes'];
        }

        if ($project->repo_url !== null) {
            $meta[] = ['label' => 'Repository', 'value' => $project->repo_url];
        }

        if ($project->live_url !== null) {
            $meta[] = ['label' => 'Live site', 'value' => $project->live_url];
        }

        if ($project->started_on !== null) {
            $meta[] = ['label' => 'Started', 'value' => $project->started_on->format('F Y')];
        }

        if ($project->ended_on !== null) {
            $meta[] = ['label' => 'Ended', 'value' => $project->ended_on->format('F Y')];
        }

        return [
            'id' => $project->id,
            'title' => $project->title,
            'summary' => $project->summary,
            'href' => $project->live_url ?? $project->repo_url,
            'meta' => $meta,
            'tags' => $tags,
            'type' => ContentType::Project->value,
            'isPlaceholder' => $project->is_placeholder,
            'repoUrl' => $project->repo_url,
            'liveUrl' => $project->live_url,
            'isFeatured' => $project->is_featured,
            'startedOn' => $project->started_on?->toDateString(),
            'endedOn' => $project->ended_on?->toDateString(),
            'techStack' => array_column($tags, 'name'),
        ];
    }

    /**
     * Map the attached tags — the project's tech stack — to the card shape.
     *
     * @return array<int, array{name: string, slug: string}>
     */
    private function tags(Project $project): array
    {
        return $project->tags
            ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
            ->values()
            ->all();
    }

    /**
     * Build the §3.9 meta description: `Selected software projects: {first three names}.`
     *
     * @param  Collection<int, Project>  $projects
     */
    private function description(Collection $projects): string
    {
        $names = $projects
            ->take(3)
            ->pluck('title')
            ->all();

        if ($names === []) {
            return 'Selected software projects.';
        }

        return 'Selected software projects: '.implode(', ', $names).'.';
    }

    /**
     * The `Home / Projects` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Projects', 'href' => StructuredData::url('projects.index')],
        ];
    }
}
