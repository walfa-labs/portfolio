<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContentType;
use App\Http\Controllers\Controller;
use App\Models\Certification;
use App\Support\ContentQuery;
use App\Support\Seo\StructuredData;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CertificationController extends Controller
{
    /**
     * The window in which an expiry raises the "Expiring" badge (§2.2).
     */
    private const EXPIRING_WITHIN_DAYS = 90;

    /**
     * Show every published certification, most recently issued first (§3.6).
     */
    public function index(): Response
    {
        /** @var EloquentCollection<int, Certification> $certifications */
        $certifications = ContentQuery::published(Certification::class)
            ->with('tags')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();

        $items = $certifications
            ->map(fn (Certification $certification): array => $this->card($certification))
            ->values()
            ->all();

        $description = $this->description($items);

        return Inertia::render('public/Certifications', [
            'certifications' => $items,
            'title' => 'Certifications - '.config('app.name'),
            'description' => $description,
            'canonical' => StructuredData::url('certifications.index'),
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::collectionPage(
                    'Certifications',
                    StructuredData::url('certifications.index'),
                    StructuredData::items($certifications),
                    $description,
                ),
                ...StructuredData::breadcrumbs($this->breadcrumbs()),
            ]),
            'noindex' => ! config('app.indexable'),
        ]);
    }

    /**
     * Shape one certification into the item the public card renders.
     *
     * The credential URL is the card's outbound link (§3.6); everything else the
     * card shows — issuer, credential id, issue and expiry dates — rides in the
     * labelled `meta` rows. `expiryState` carries the badge state so the card can
     * render it without re-deriving the 90-day window.
     *
     * @return array<string, mixed>
     */
    private function card(Certification $certification): array
    {
        $expiresOn = $certification->expires_on;
        $expiryState = $this->expiryState($certification);
        $expiryLabel = $expiryState === null || $expiresOn === null
            ? null
            : $this->expiryLabel($expiryState, $expiresOn);

        $meta = [
            ['label' => 'Issuer', 'value' => $certification->issuer],
            ['label' => 'Issued', 'value' => $certification->issued_on->format('F Y')],
        ];

        if ($certification->credential_id !== null) {
            $meta[] = ['label' => 'Credential ID', 'value' => $certification->credential_id];
        }

        if ($expiresOn !== null) {
            $meta[] = $expiryLabel === null
                ? ['label' => 'Expires', 'value' => $expiresOn->format('j F Y')]
                : ['label' => 'Expiry', 'value' => $expiryLabel];
        }

        return [
            'id' => $certification->id,
            'title' => $certification->title,
            'summary' => $certification->summary,
            'href' => $certification->credential_url,
            'meta' => $meta,
            'tags' => $this->tags($certification),
            'type' => ContentType::Certification->value,
            'isPlaceholder' => $certification->is_placeholder,
            'issuer' => $certification->issuer,
            'credentialId' => $certification->credential_id,
            'credentialUrl' => $certification->credential_url,
            'issuedOn' => $certification->issued_on->toDateString(),
            'expiresOn' => $expiresOn?->toDateString(),
            'expiryState' => $expiryState,
            'expiryLabel' => $expiryLabel,
        ];
    }

    /**
     * The exact wording §2.2 fixes for the expiry badge.
     */
    private function expiryLabel(string $expiryState, CarbonInterface $expiresOn): string
    {
        return $expiryState === 'expired'
            ? 'Expired'
            : 'Expiring '.$expiresOn->format('j F Y');
    }

    /**
     * Classify the certification's expiry against the 90-day window (§2.2).
     */
    private function expiryState(Certification $certification): ?string
    {
        $expiresOn = $certification->expires_on;

        return match (true) {
            $expiresOn === null => null,
            $expiresOn->isPast() => 'expired',
            $expiresOn->lessThanOrEqualTo(now()->addDays(self::EXPIRING_WITHIN_DAYS)) => 'expiring',
            default => null,
        };
    }

    /**
     * Map the attached tags to the shape the public card renders.
     *
     * @return array<int, array{name: string, slug: string}>
     */
    private function tags(Certification $certification): array
    {
        return $certification->tags
            ->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
            ->values()
            ->all();
    }

    /**
     * Build the §3.9 meta description: `{n} certifications including {latest}.`
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function description(array $items): string
    {
        $count = count($items);

        if ($count === 0) {
            return 'Professional certifications and credentials.';
        }

        $noun = Str::plural('certification', $count);

        return "{$count} {$noun} including {$items[0]['title']}.";
    }

    /**
     * The `Home / Certifications` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Certifications', 'href' => StructuredData::url('certifications.index')],
        ];
    }
}
