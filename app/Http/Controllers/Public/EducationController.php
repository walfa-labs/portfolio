<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Education;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The education list (§3.6).
 *
 * `/education` renders every published record — usually one to three — so it is
 * not paginated, and it has no detail page: the card is the whole record.
 */
class EducationController extends Controller
{
    /**
     * Show the published degrees and programmes, most recently finished first.
     *
     * §2.3 orders education on `-end_date`. The `CASE` expression is the
     * portable form of "records without an end date last": SQLite sorts NULLs
     * first under `DESC` and MariaDB sorts them last, so the raw column alone
     * would order the two engines differently. The `id` tiebreaker keeps the
     * result deterministic (§2.9 item 9).
     */
    public function index(): Response
    {
        /** @var EloquentCollection<int, Education> $education */
        $education = ContentQuery::published(Education::class)
            ->with('tags')
            ->orderByRaw('CASE WHEN end_date IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get();

        $items = array_values(
            $education
                ->map(fn (Education $entry): array => $this->card($entry))
                ->all(),
        );

        $description = $this->description($items);

        return Inertia::render('public/Education', [
            'education' => $items,
            'title' => 'Education - '.config('app.name'),
            'description' => $description,
            'canonical' => StructuredData::url('education.index'),
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::collectionPage(
                    'Education',
                    StructuredData::url('education.index'),
                    StructuredData::items($education),
                    $description,
                ),
                ...StructuredData::breadcrumbs($this->breadcrumbs()),
            ]),
            'noindex' => ! config('app.indexable'),
        ]);
    }

    /**
     * Shape one record into the item the public card renders.
     *
     * The institution's own site is the outbound artefact, so it is the card's
     * `href`; a record without one renders as a card with no link (§3.6).
     *
     * @return array<string, mixed>
     */
    private function card(Education $education): array
    {
        $meta = [];

        if ($education->field_of_study !== null) {
            $meta[] = ['label' => 'Field', 'value' => $education->field_of_study];
        }

        $meta[] = ['label' => 'Institution', 'value' => $education->institution];

        if ($years = $this->years($education)) {
            $meta[] = ['label' => 'Dates', 'value' => $years];
        }

        return [
            'id' => $education->id,
            'title' => $education->title,
            'summary' => $education->summary,
            'href' => $education->institution_url,
            'meta' => $meta,
            'tags' => $this->tags($education),
            'type' => ContentType::Education->value,
            'isPlaceholder' => $education->is_placeholder,
            'institution' => $education->institution,
            'institutionUrl' => $education->institution_url,
            'fieldOfStudy' => $education->field_of_study,
            'startDate' => $education->start_date?->toDateString(),
            'endDate' => $education->end_date?->toDateString(),
        ];
    }

    /**
     * Render the attendance period as years — the display §2.3 asks for.
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
    private function tags(Education $education): array
    {
        return array_values(
            $education->tags
                ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
                ->all(),
        );
    }

    /**
     * Build the §3.9 meta description: `{n} degrees and programmes.`
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function description(array $items): string
    {
        $count = count($items);

        if ($count === 0) {
            return 'Degrees and programmes.';
        }

        return "{$count} ".Str::plural('degree', $count).' and '.Str::plural('programme', $count).'.';
    }

    /**
     * The `Home / Education` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Education', 'href' => StructuredData::url('education.index')],
        ];
    }
}
