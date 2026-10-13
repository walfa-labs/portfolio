<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AwardController extends Controller
{
    /**
     * Show every published award, most recently received first (§3.6).
     */
    public function index(): Response
    {
        /** @var EloquentCollection<int, Award> $awards */
        $awards = ContentQuery::published(Award::class)
            ->with('tags')
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->get();

        $items = $awards
            ->map(fn (Award $award): array => $this->card($award))
            ->values()
            ->all();

        $description = $this->description($items);

        return Inertia::render('public/Awards', [
            'awards' => $items,
            'title' => 'Awards - '.config('app.name'),
            'description' => $description,
            'canonical' => StructuredData::url('awards.index'),
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::collectionPage(
                    'Awards',
                    StructuredData::url('awards.index'),
                    StructuredData::items($awards),
                    $description,
                ),
                ...StructuredData::breadcrumbs($this->breadcrumbs()),
            ]),
            'noindex' => ! config('app.indexable'),
        ]);
    }

    /**
     * Shape one award into the item the public card renders.
     *
     * An award has no outbound artefact, so it has no `href`; the awarding body
     * and the date carry the whole record (§3.6).
     *
     * @return array<string, mixed>
     */
    private function card(Award $award): array
    {
        return [
            'id' => $award->id,
            'title' => $award->title,
            'summary' => $award->summary,
            'href' => null,
            'meta' => [
                ['label' => 'Awarded by', 'value' => $award->awarding_body],
                ['label' => 'Awarded', 'value' => $award->awarded_at->format('F Y')],
            ],
            'tags' => $this->tags($award),
            'type' => ContentType::Award->value,
            'isPlaceholder' => $award->is_placeholder,
            'awardingBody' => $award->awarding_body,
            'awardedAt' => $award->awarded_at->toDateString(),
        ];
    }

    /**
     * Map the attached tags to the shape the public card renders.
     *
     * @return array<int, array{name: string, slug: string}>
     */
    private function tags(Award $award): array
    {
        return $award->tags
            ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
            ->values()
            ->all();
    }

    /**
     * Build the §3.9 meta description: `{n} awards and recognitions.`
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function description(array $items): string
    {
        $count = count($items);

        if ($count === 0) {
            return 'Awards and recognitions.';
        }

        return "{$count} ".Str::plural('award', $count).' and '.Str::plural('recognition', $count).'.';
    }

    /**
     * The `Home / Awards` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Awards', 'href' => StructuredData::url('awards.index')],
        ];
    }
}
