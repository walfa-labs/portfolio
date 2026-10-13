<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page (§3.5).
 *
 * The composition is fixed: hero, CTAs, "at a glance", contact affordances, then
 * seven sections. Sections one and two are eager props because a crawler only
 * ever sees the initial document — a deferred prop resolves in a follow-up
 * request, so it is absent from the first response (§3.5, "SSR caveat").
 */
class HomeController extends Controller
{
    /**
     * §2.3: "Selected work" is three to six featured projects.
     */
    private const FEATURED_PROJECT_LIMIT = 6;

    /**
     * §3.5: the three most recent roles.
     */
    private const RECENT_EXPERIENCE_LIMIT = 3;

    /**
     * §3.5: the three most recent published writings.
     */
    private const RECENT_WRITING_LIMIT = 3;

    /**
     * How many tags the skills cloud shows.
     */
    private const SKILL_TAG_LIMIT = 20;

    /**
     * The ceiling §3.9 puts on every meta description.
     */
    private const META_DESCRIPTION_LIMIT = 155;

    /**
     * Render the home page.
     */
    public function __invoke(): Response
    {
        $profile = Profile::query()->first();

        /** @var EloquentCollection<int, Project> $featuredProjects */
        $featuredProjects = ContentQuery::published(Project::class)
            ->with('tags')
            ->where('is_featured', true)
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->limit(self::FEATURED_PROJECT_LIMIT)
            ->get();

        /** @var EloquentCollection<int, WorkExperience> $recentExperience */
        $recentExperience = ContentQuery::published(WorkExperience::class)
            ->with('tags')
            ->orderByRaw('CASE WHEN end_date IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_EXPERIENCE_LIMIT)
            ->get();

        $projectCards = $featuredProjects
            ->map(fn (Project $project): array => $this->projectCard($project))
            ->values()
            ->all();

        $experienceEntries = $recentExperience
            ->map(fn (WorkExperience $role): array => $this->experienceEntry($role))
            ->values()
            ->all();

        return Inertia::render('public/Home', [
            'profile' => $this->profile($profile),
            'atAGlance' => $this->atAGlance($profile, $recentExperience),
            'featuredProjects' => $projectCards,
            'featuredProject' => $projectCards[0] ?? null,
            'recentExperience' => $experienceEntries,
            'recentWritings' => Inertia::defer(fn (): array => $this->recentWritings()),
            'skillTags' => Inertia::defer(fn (): array => $this->skillTags()),
            'education' => Inertia::defer(fn (): array => $this->educationCards()),
            'certifications' => Inertia::defer(fn (): array => $this->certificationCards()),
            'publications' => Inertia::defer(fn (): array => $this->publicationCards()),
            'awards' => Inertia::defer(fn (): array => $this->awardCards()),
            'title' => $this->title($profile),
            'description' => $this->description($profile),
            'canonical' => StructuredData::url('home'),
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::profilePage(StructuredData::url('home')),
            ]),
            'noindex' => ! config('app.indexable'),
        ]);
    }

    /**
     * Build the §3.9 title: `{Name} — {Role}`.
     */
    private function title(?Profile $profile): string
    {
        $name = (string) config('app.name');
        $role = trim((string) $profile?->role_headline);

        return $role === '' ? $name : $name.' — '.$role;
    }

    /**
     * Build the §3.9 meta description: the positioning sentence, truncated to
     * 155 characters.
     */
    private function description(?Profile $profile): string
    {
        $sentence = trim((string) $profile?->positioning_sentence);

        if ($sentence === '') {
            return 'Portfolio, writing and curriculum vitae.';
        }

        if (Str::length($sentence) <= self::META_DESCRIPTION_LIMIT) {
            return $sentence;
        }

        return rtrim(Str::substr($sentence, 0, self::META_DESCRIPTION_LIMIT - 1)).'…';
    }

    /**
     * Expose the hero's copy (§3.5 items 1, 2 and 5).
     *
     * @return array<string, mixed>|null
     */
    private function profile(?Profile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        return [
            'name' => $profile->name,
            'role_headline' => $profile->role_headline,
            'positioning_sentence' => $profile->positioning_sentence,
            'location' => $profile->location,
            'availability' => $profile->availability,
            'email' => $profile->email,
            'github_url' => $profile->github_url,
            'linkedin_url' => $profile->linkedin_url,
            'orcid_url' => $profile->orcid_url,
        ];
    }

    /**
     * Build the "at a glance" strip (§3.5 item 4).
     *
     * Every value is seed data: the years are the distance from
     * `years_experience_start`, and the current role is the open-ended record
     * from the same ordered set the timeline renders — nothing is invented here.
     *
     * @param  EloquentCollection<int, WorkExperience>  $recentExperience
     * @return list<array{label: string, value: string}>
     */
    private function atAGlance(?Profile $profile, EloquentCollection $recentExperience): array
    {
        $facts = [];

        $start = $profile?->years_experience_start;

        if ($start !== null) {
            $facts[] = [
                'label' => 'Experience',
                'value' => ((int) $start->diffInYears(now())).' years',
            ];
        }

        $current = $recentExperience->first(fn ($role): bool => $role->end_date === null);

        if ($current !== null) {
            $facts[] = ['label' => 'Current', 'value' => $current->title.' @ '.$current->employer];
        }

        $location = $profile?->location;

        if (filled($location)) {
            $facts[] = ['label' => 'Location', 'value' => $location];
        }

        $availability = $profile?->availability;

        if (filled($availability)) {
            $facts[] = ['label' => 'Availability', 'value' => $availability];
        }

        return $facts;
    }

    /**
     * The three most recent published writings (§3.5 section 3).
     *
     * @return list<array<string, mixed>>
     */
    private function recentWritings(): array
    {
        /** @var EloquentCollection<int, Writing> $writings */
        $writings = ContentQuery::published(Writing::class)
            ->with('tags')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_WRITING_LIMIT)
            ->get();

        return array_values(
            $writings
                ->map(fn (Writing $writing): array => $this->writingCard($writing))
                ->all(),
        );
    }

    /**
     * The tag cloud, most-used first (§3.5 section 4).
     *
     * The count is published-only and comes from the same
     * {@see ContentQuery::publishedTagCounts()} `/tags` aggregates through, so
     * the two surfaces never disagree about the same tag: a tag attached only to
     * drafts has no published items and counts zero here too. The name ordering
     * is lower-cased because §2.9 item 4 forbids ordering on a raw label.
     *
     * @return list<array{name: string, slug: string, count: int}>
     */
    private function skillTags(): array
    {
        $counts = ContentQuery::publishedTagCounts();

        return array_values(
            Tag::query()
                ->get()
                ->sort(fn (Tag $a, Tag $b): int => ($counts[$b->id] ?? 0) <=> ($counts[$a->id] ?? 0)
                    ?: strcmp(mb_strtolower($a->name), mb_strtolower($b->name))
                    ?: $a->id <=> $b->id)
                ->take(self::SKILL_TAG_LIMIT)
                ->map(fn (Tag $tag): array => [
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'count' => $counts[$tag->id] ?? 0,
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * The education column of §3.5 section 5.
     *
     * @return list<array<string, mixed>>
     */
    private function educationCards(): array
    {
        /** @var EloquentCollection<int, Education> $education */
        $education = ContentQuery::published(Education::class)
            ->with('tags')
            ->orderByRaw('CASE WHEN end_date IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get();

        return array_values(
            $education
                ->map(fn (Education $entry): array => $this->educationCard($entry))
                ->all(),
        );
    }

    /**
     * The certification column of §3.5 section 5.
     *
     * @return list<array<string, mixed>>
     */
    private function certificationCards(): array
    {
        /** @var EloquentCollection<int, Certification> $certifications */
        $certifications = ContentQuery::published(Certification::class)
            ->with('tags')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();

        return array_values(
            $certifications
                ->map(fn (Certification $certification): array => $this->certificationCard($certification))
                ->all(),
        );
    }

    /**
     * §3.5 section 6 — rendered only when it has something to show.
     *
     * @return list<array<string, mixed>>
     */
    private function publicationCards(): array
    {
        /** @var EloquentCollection<int, Publication> $publications */
        $publications = ContentQuery::published(Publication::class)
            ->with('tags')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();

        return array_values(
            $publications
                ->map(fn (Publication $publication): array => $this->publicationCard($publication))
                ->all(),
        );
    }

    /**
     * §3.5 section 6 — rendered only when it has something to show.
     *
     * @return list<array<string, mixed>>
     */
    private function awardCards(): array
    {
        /** @var EloquentCollection<int, Award> $awards */
        $awards = ContentQuery::published(Award::class)
            ->with('tags')
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->get();

        return array_values(
            $awards
                ->map(fn (Award $award): array => $this->awardCard($award))
                ->all(),
        );
    }

    /**
     * Shape one role into the timeline entry the page renders.
     *
     * @return array<string, mixed>
     */
    private function experienceEntry(WorkExperience $experience): array
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
     * Shape one featured project into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function projectCard(Project $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'summary' => $project->summary,
            'href' => $project->live_url ?? $project->repo_url,
            'meta' => $this->meta([
                ['label' => 'Repository', 'value' => $project->repo_url],
                ['label' => 'Live site', 'value' => $project->live_url],
                ['label' => 'Started', 'value' => $project->started_on?->format('F Y')],
            ]),
            'tags' => $this->tags($project),
            'type' => ContentType::Project->value,
            'isPlaceholder' => $project->is_placeholder,
        ];
    }

    /**
     * Shape one writing into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function writingCard(Writing $writing): array
    {
        $readingTime = $writing->reading_time_minutes;

        return [
            'id' => $writing->id,
            'title' => $writing->title,
            'summary' => $writing->excerpt ?? $writing->summary,
            'href' => StructuredData::url('writing.show', $writing),
            'meta' => $this->meta([
                ['label' => 'Published', 'value' => $writing->published_at?->format('F Y')],
                ['label' => 'Reading time', 'value' => $readingTime === null ? null : $readingTime.' min read'],
            ]),
            'tags' => $this->tags($writing),
            'type' => ContentType::Writing->value,
            'isPlaceholder' => $writing->is_placeholder,
            'publishedAt' => $writing->published_at?->toDateString(),
        ];
    }

    /**
     * Shape one degree into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function educationCard(Education $education): array
    {
        return [
            'id' => $education->id,
            'title' => $education->title,
            'summary' => $education->summary,
            'href' => $education->institution_url,
            'meta' => $this->meta([
                ['label' => 'Institution', 'value' => $education->institution],
                ['label' => 'Field', 'value' => $education->field_of_study],
                ['label' => 'Dates', 'value' => $this->years($education)],
            ]),
            'tags' => $this->tags($education),
            'type' => ContentType::Education->value,
            'isPlaceholder' => $education->is_placeholder,
        ];
    }

    /**
     * Shape one certification into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function certificationCard(Certification $certification): array
    {
        return [
            'id' => $certification->id,
            'title' => $certification->title,
            'summary' => $certification->summary,
            'href' => $certification->credential_url,
            'meta' => $this->meta([
                ['label' => 'Issuer', 'value' => $certification->issuer],
                ['label' => 'Issued', 'value' => $certification->issued_on->format('F Y')],
                ['label' => 'Expires', 'value' => $certification->expires_on?->format('F Y')],
            ]),
            'tags' => $this->tags($certification),
            'type' => ContentType::Certification->value,
            'isPlaceholder' => $certification->is_placeholder,
        ];
    }

    /**
     * Shape one publication into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function publicationCard(Publication $publication): array
    {
        return [
            'id' => $publication->id,
            'title' => $publication->title,
            'summary' => $publication->summary,
            'href' => $publication->doi_url,
            'meta' => $this->meta([
                ['label' => 'Venue', 'value' => $publication->venue],
                ['label' => 'Authors', 'value' => $publication->authors],
                ['label' => 'Published', 'value' => $publication->issued_on->format('F Y')],
            ]),
            'tags' => $this->tags($publication),
            'type' => ContentType::Publication->value,
            'isPlaceholder' => $publication->is_placeholder,
        ];
    }

    /**
     * Shape one award into the item the public card renders.
     *
     * @return array<string, mixed>
     */
    private function awardCard(Award $award): array
    {
        return [
            'id' => $award->id,
            'title' => $award->title,
            'summary' => $award->summary,
            'href' => null,
            'meta' => $this->meta([
                ['label' => 'Awarded by', 'value' => $award->awarding_body],
                ['label' => 'Awarded', 'value' => $award->awarded_at->format('F Y')],
            ]),
            'tags' => $this->tags($award),
            'type' => ContentType::Award->value,
            'isPlaceholder' => $award->is_placeholder,
        ];
    }

    /**
     * Render an attendance period as years — the display §2.3 asks for.
     */
    private function years(Education $education): ?string
    {
        $start = $education->start_date?->format('Y');
        $end = $education->end_date?->format('Y');

        if ($start === null) {
            return $end;
        }

        return $start.' – '.($end ?? 'Present');
    }

    /**
     * Map the attached tags to the shape the public chip renders.
     *
     * @return list<array{name: string, slug: string}>
     */
    private function tags(Award|Certification|Education|Project|Publication|WorkExperience|Writing $model): array
    {
        return array_values(
            $model->tags
                ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
                ->all(),
        );
    }

    /**
     * Keep the meta rows that carry a value.
     *
     * @param  list<array{label: string, value: string|null}>  $rows
     * @return list<array{label: string, value: string}>
     */
    private function meta(array $rows): array
    {
        $meta = [];

        foreach ($rows as $row) {
            if (filled($row['value'])) {
                $meta[] = ['label' => $row['label'], 'value' => (string) $row['value']];
            }
        }

        return $meta;
    }
}
