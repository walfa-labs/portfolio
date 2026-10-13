<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Media;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable CV (§3.6).
 *
 * Six sections — experience, education, certifications, publications, projects
 * and awards — each in reverse-chronological order, plus the profile summary and
 * the skill vocabulary. `@media print` rules in `resources/css/app.css` turn this
 * page into the artefact, so no PDF is generated anywhere.
 *
 * Experience entries carry the props `TimelineItem` declares; every other section
 * renders the same generic entry shape, because the timeline component is built
 * for a job entry (an employer, a location and a start date are all required) and
 * a publication has none of those.
 */
class CvController extends Controller
{
    /**
     * Render the CV.
     *
     * `/cv` is `noindex` while the site is not indexable (§14 Q4 keeps the
     * conservative default), so the flag is the same `APP_INDEXABLE` switch every
     * other page reads rather than a second setting.
     */
    public function __invoke(): Response
    {
        $profile = Profile::query()->first();
        $description = 'Full curriculum vitae: experience, education, publications and projects.';

        return Inertia::render('public/Cv', [
            'profile' => $profile === null ? null : [
                'name' => $profile->name,
                'roleHeadline' => $profile->role_headline,
                'location' => $profile->location,
                'availability' => $profile->availability,
                'email' => $profile->email,
                'githubUrl' => $profile->github_url,
                'linkedinUrl' => $profile->linkedin_url,
                'orcidUrl' => $profile->orcid_url,
            ],
            'summary' => $profile?->positioning_sentence,
            'skills' => $this->skills(),
            'sections' => $this->sections(),
            'downloadUrl' => $this->downloadUrl(),
            'title' => 'CV - '.config('app.name'),
            'description' => $description,
            'canonical' => StructuredData::url('cv'),
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::profilePage(StructuredData::url('cv')),
                ...StructuredData::breadcrumbs($this->breadcrumbs()),
            ]),
            'noindex' => ! config('app.indexable'),
        ]);
    }

    /**
     * The CV download URL, but only for a user who owns a processed CV.
     *
     * The download route is scoped to the requesting user's own processed `cv`
     * row (§9.7, pinned decision 14), so emitting the URL unconditionally
     * would hand every visitor — and every other authenticated user — a link
     * that answers 404 (pinned decision 15). Guests and users without a CV
     * get `null`, which hides the button.
     */
    private function downloadUrl(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $hasProcessedCv = Media::query()
            ->where('collection', 'cv')
            ->where('mediable_type', 'user')
            ->where('mediable_id', $user->getAuthIdentifier())
            ->where('status', 'processed')
            ->exists();

        return $hasProcessedCv ? route('cv.download') : null;
    }

    /**
     * The `Home / CV` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'CV', 'href' => StructuredData::url('cv')],
        ];
    }

    /**
     * Build the six CV sections in fixed order.
     *
     * Every section is emitted even when it is empty, so the page shape does not
     * change with the dataset.
     *
     * @return list<array{key: string, title: string, type: string, items: array<int, array<string, mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'key' => 'experience',
                'title' => 'Experience',
                'type' => ContentType::WorkExperience->value,
                'items' => $this->experienceItems(),
            ],
            [
                'key' => 'education',
                'title' => 'Education',
                'type' => ContentType::Education->value,
                'items' => $this->educationItems(),
            ],
            [
                'key' => 'certifications',
                'title' => 'Certifications',
                'type' => ContentType::Certification->value,
                'items' => $this->certificationItems(),
            ],
            [
                'key' => 'publications',
                'title' => 'Publications',
                'type' => ContentType::Publication->value,
                'items' => $this->publicationItems(),
            ],
            [
                'key' => 'projects',
                'title' => 'Projects',
                'type' => ContentType::Project->value,
                'items' => $this->projectItems(),
            ],
            [
                'key' => 'awards',
                'title' => 'Awards',
                'type' => ContentType::Award->value,
                'items' => $this->awardItems(),
            ],
        ];
    }

    /**
     * Get the roles: current first, then most recently ended (pinned decision 19).
     *
     * The null-first ordering is a portable `CASE` rather than an engine-specific
     * `IS NULL` trick, so the query runs unchanged on SQLite and MariaDB.
     *
     * @return array<int, array<string, mixed>>
     */
    private function experienceItems(): array
    {
        /** @var Collection<int, WorkExperience> $entries */
        $entries = ContentQuery::published(WorkExperience::class)
            ->with('tags')
            ->orderByRaw('CASE WHEN end_date IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get();

        return $entries->map(fn (WorkExperience $entry): array => [
            'id' => $entry->id,
            'title' => $entry->title,
            'organisation' => $entry->employer,
            'organisationUrl' => $entry->employer_url,
            'location' => $entry->location,
            'startDate' => $entry->start_date->toDateString(),
            'endDate' => $entry->end_date?->toDateString(),
            'date' => null,
            'summary' => $entry->summary,
            'highlights' => $entry->highlights ?? [],
            'meta' => [],
            'link' => null,
            'tags' => $this->tags($entry->tags),
            'type' => ContentType::WorkExperience->value,
            'isPlaceholder' => $entry->is_placeholder,
        ])->all();
    }

    /**
     * Get the degrees and programmes, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function educationItems(): array
    {
        /** @var Collection<int, Education> $entries */
        $entries = ContentQuery::published(Education::class)
            ->with('tags')
            ->orderByRaw('CASE WHEN end_date IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get();

        return $entries->map(fn (Education $entry): array => [
            'id' => $entry->id,
            'title' => $entry->title,
            'organisation' => $entry->institution,
            'organisationUrl' => $entry->institution_url,
            'location' => null,
            'startDate' => $entry->start_date?->toDateString(),
            'endDate' => $entry->end_date?->toDateString(),
            'date' => null,
            'summary' => $entry->summary,
            'highlights' => [],
            'meta' => $this->meta([
                'Field of study' => $entry->field_of_study,
            ]),
            'link' => null,
            'tags' => $this->tags($entry->tags),
            'type' => ContentType::Education->value,
            'isPlaceholder' => $entry->is_placeholder,
        ])->all();
    }

    /**
     * Get the credentials, most recently issued first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function certificationItems(): array
    {
        /** @var Collection<int, Certification> $entries */
        $entries = ContentQuery::published(Certification::class)
            ->with('tags')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();

        return $entries->map(fn (Certification $entry): array => [
            'id' => $entry->id,
            'title' => $entry->title,
            'organisation' => $entry->issuer,
            'organisationUrl' => null,
            'location' => null,
            'startDate' => null,
            'endDate' => null,
            'date' => $entry->issued_on->toDateString(),
            'summary' => $entry->summary,
            'highlights' => [],
            'meta' => $this->meta([
                'Credential ID' => $entry->credential_id,
                'Expires' => $entry->expires_on?->format('F Y'),
            ]),
            'link' => $this->link($entry->credential_url, 'Credential'),
            'tags' => $this->tags($entry->tags),
            'type' => ContentType::Certification->value,
            'isPlaceholder' => $entry->is_placeholder,
        ])->all();
    }

    /**
     * Get the peer-reviewed work, most recently issued first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function publicationItems(): array
    {
        /** @var Collection<int, Publication> $entries */
        $entries = ContentQuery::published(Publication::class)
            ->with('tags')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();

        return $entries->map(fn (Publication $entry): array => [
            'id' => $entry->id,
            'title' => $entry->title,
            'organisation' => $entry->venue,
            'organisationUrl' => null,
            'location' => null,
            'startDate' => null,
            'endDate' => null,
            'date' => $entry->issued_on->toDateString(),
            'summary' => $entry->summary,
            'highlights' => [],
            'meta' => $this->meta([
                'Authors' => $entry->authors,
                'DOI' => $entry->doi,
            ]),
            'link' => $this->link($entry->doi_url, 'DOI'),
            'tags' => $this->tags($entry->tags),
            'type' => ContentType::Publication->value,
            'isPlaceholder' => $entry->is_placeholder,
        ])->all();
    }

    /**
     * Get the selected projects, most recently started first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function projectItems(): array
    {
        /** @var Collection<int, Project> $entries */
        $entries = ContentQuery::published(Project::class)
            ->with('tags')
            ->orderByRaw('CASE WHEN started_on IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->get();

        return $entries->map(fn (Project $entry): array => [
            'id' => $entry->id,
            'title' => $entry->title,
            'organisation' => null,
            'organisationUrl' => null,
            'location' => null,
            'startDate' => $entry->started_on?->toDateString(),
            'endDate' => $entry->ended_on?->toDateString(),
            'date' => null,
            'summary' => $entry->summary,
            'highlights' => [],
            'meta' => [],
            'link' => $this->link($entry->repo_url, 'Repository')
                ?? $this->link($entry->live_url, 'Live site'),
            'tags' => $this->tags($entry->tags),
            'type' => ContentType::Project->value,
            'isPlaceholder' => $entry->is_placeholder,
        ])->all();
    }

    /**
     * Get the awards and recognitions, most recently awarded first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function awardItems(): array
    {
        /** @var Collection<int, Award> $entries */
        $entries = ContentQuery::published(Award::class)
            ->with('tags')
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->get();

        return $entries->map(fn (Award $entry): array => [
            'id' => $entry->id,
            'title' => $entry->title,
            'organisation' => $entry->awarding_body,
            'organisationUrl' => null,
            'location' => null,
            'startDate' => null,
            'endDate' => null,
            'date' => $entry->awarded_at->toDateString(),
            'summary' => $entry->summary,
            'highlights' => [],
            'meta' => [],
            'link' => null,
            'tags' => $this->tags($entry->tags),
            'type' => ContentType::Award->value,
            'isPlaceholder' => $entry->is_placeholder,
        ])->all();
    }

    /**
     * Get the skill vocabulary: the names of tags carried by published content,
     * most-used first and alphabetical within a tie.
     *
     * Skills are the tag vocabulary — the same set `Person.knowsAbout` publishes
     * in the structured data (§3.9).
     *
     * @return array<int, string>
     */
    private function skills(): array
    {
        $counts = ContentQuery::publishedTagCounts();

        if ($counts === []) {
            return [];
        }

        return Tag::query()
            ->whereIn('id', array_keys($counts))
            ->orderBy('name')
            ->get()
            ->sortByDesc(fn (Tag $tag): int => $counts[$tag->id] ?? 0)
            ->map(fn (Tag $tag): string => $tag->name)
            ->values()
            ->all();
    }

    /**
     * Shape the label/value pairs an entry renders, dropping empty ones.
     *
     * @param  array<string, string|null>  $pairs
     * @return list<array{label: string, value: string}>
     */
    private function meta(array $pairs): array
    {
        $meta = [];

        foreach ($pairs as $label => $value) {
            if (filled($value)) {
                $meta[] = ['label' => $label, 'value' => (string) $value];
            }
        }

        return $meta;
    }

    /**
     * Shape the outbound link an entry carries, when it has one.
     *
     * @return array{href: string, label: string}|null
     */
    private function link(?string $url, string $label): ?array
    {
        return filled($url) ? ['href' => (string) $url, 'label' => $label] : null;
    }

    /**
     * Shape a tag list for the card component.
     *
     * @param  Collection<int, Tag>  $tags
     * @return array<int, array{slug: string, name: string}>
     */
    private function tags(Collection $tags): array
    {
        return $tags
            ->map(fn (Tag $tag): array => ['slug' => $tag->slug, 'name' => $tag->name])
            ->values()
            ->all();
    }
}
