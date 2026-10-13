<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\Seo\StructuredData;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy policy (§8.7).
 *
 * The ten required content blocks are static prose in `public/Privacy.vue`; the
 * controller supplies the values that must stay in step with configuration — the
 * controller identity, the current policy version and the date it took effect —
 * plus the page's SEO contract.
 *
 * §3.2 declares the route `public;max_age=3600;etag`, but the response is
 * per-visitor: it embeds the `privacy` prop — the opt-out state and the stored
 * consent record surfaced at `#choices` — and a per-visitor CSP header, so a
 * shared cache could serve one visitor's state to another. The route therefore
 * carries no `cache.headers` and keeps the framework default,
 * `Cache-Control: no-cache, private`; see the route comment in `routes/web.php`.
 */
class PrivacyController extends Controller
{
    /**
     * The description §3.9 fixes for this route.
     */
    private const DESCRIPTION = 'How this site handles your data.';

    /**
     * The published versions of this policy.
     *
     * `CONSENT_POLICY_VERSION` is the consent record's version (§8.3), so this
     * history is the human-readable half of the same number: a visitor can see
     * what changed when their stored consent stopped counting.
     *
     * @var list<array{version: int, effective: string, summary: string}>
     */
    private const VERSION_HISTORY = [
        [
            'version' => 1,
            'effective' => '2026-10-12',
            'summary' => 'First published version: the cookie and storage inventory, the analytics and advertising disclosures, the retention periods and the data-subject rights.',
        ],
    ];

    /**
     * Render the policy.
     */
    public function __invoke(): Response
    {
        $contactEmail = config('mail.contact_recipient');

        return Inertia::render('public/Privacy', [
            'contactEmail' => is_string($contactEmail) && filled($contactEmail) ? $contactEmail : null,
            'policyVersion' => $this->policyVersion(),
            'lastUpdated' => $this->lastUpdated(),
            'versionHistory' => self::VERSION_HISTORY,
            'title' => 'Privacy - '.config('app.name'),
            'description' => self::DESCRIPTION,
            'canonical' => StructuredData::url('privacy'),
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::page(
                    'WebPage',
                    StructuredData::url('privacy'),
                    'Privacy',
                    self::DESCRIPTION,
                ),
                ...StructuredData::breadcrumbs($this->breadcrumbs()),
            ]),
            'noindex' => ! config('app.indexable'),
        ]);
    }

    /**
     * The `Home / Privacy` trail §3.4 renders and §3.9 mirrors.
     *
     * @return list<array{title: string, href: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['title' => 'Home', 'href' => StructuredData::url('home')],
            ['title' => 'Privacy', 'href' => StructuredData::url('privacy')],
        ];
    }

    /**
     * Get the version of the policy the consent record is keyed to.
     *
     * Falls back to the first version when no config file maps
     * `CONSENT_POLICY_VERSION` yet.
     */
    private function policyVersion(): int
    {
        return max(1, (int) config('consent.policy_version', 1));
    }

    /**
     * Get the effective date of the current version.
     */
    private function lastUpdated(): string
    {
        $current = $this->policyVersion();
        $effective = self::VERSION_HISTORY[0]['effective'];

        foreach (self::VERSION_HISTORY as $entry) {
            if ($entry['version'] <= $current) {
                $effective = $entry['effective'];
            }
        }

        return $effective;
    }
}
