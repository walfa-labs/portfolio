<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Privacy policy (§8.7)
|--------------------------------------------------------------------------
|
| `/privacy` must render all ten content blocks §8.7 lists. The blocks are
| static prose in `resources/js/pages/public/Privacy.vue`; the controller only
| supplies the values that must stay in step with configuration (the contact
| address, the policy version and the date it took effect) plus the SEO
| contract.
|
| There is no SSR server listening on 127.0.0.1:13714 during the suite, so the
| response body is the client-side shell and the prose cannot be read back from
| it. Each block is therefore asserted against the component source — the
| artefact that would lose a block — and the props are asserted through
| Inertia. One test per block, so a missing block names itself.
|
| §8.7 item 9 also says the `#choices` anchor hosts `PrivacyChoicesLink`. That
| component belongs to phase 7 (§8.3, consent banner); the anchor is asserted
| here, the component is not.
|
*/

function privacyPolicySource(): string
{
    return File::get(base_path('resources/js/pages/public/Privacy.vue'));
}

/**
 * Assert the component still carries every marker a §8.7 block needs.
 *
 * @param  list<string>  $markers
 */
function expectPrivacyBlock(array $markers): void
{
    $source = privacyPolicySource();

    foreach ($markers as $marker) {
        expect($source)->toContain($marker);
    }
}

test('the privacy page renders public/Privacy with the policy props', function () {
    config([
        'mail.contact_recipient' => 'privacy@example.test',
        'consent.policy_version' => 1,
    ]);

    $response = $this->get(route('privacy'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Privacy')
        ->where('contactEmail', 'privacy@example.test')
        ->where('policyVersion', 1)
        ->where('lastUpdated', '2026-10-12')
        ->has('versionHistory', 1)
        ->where('versionHistory.0.version', 1)
        ->where('versionHistory.0.effective', '2026-10-12')
        ->where('canonical', rtrim((string) config('app.url'), '/').'/privacy')
    );
});

test('§8.7 block 1 — controller identity, contact address, last-updated date and version history', function () {
    expectPrivacyBlock([
        'Who is responsible',
        'Last updated',
        'Policy version',
        'contactEmail',
        'versionHistory',
        'Version history',
    ]);
});

test('§8.7 block 2 — the §8.1 storage table including the "Essential?" column', function () {
    expectPrivacyBlock([
        'What this site stores on your device',
        'Essential?',
        'Encrypted / exempt today',
        'laravel-session (SESSION_COOKIE)',
        'XSRF-TOKEN',
        'remember_web_<sha1>',
        'appearance',
        'sidebar_state',
        'inertiaLocationVisit',
        'historyKey, historyIv',
        'consent',
        '_clck, _clsk',
        'CLID, MUID, ANONCHK, MR, SM',
        'Google advertising cookies on *.google.com and *.doubleclick.net',
    ]);
});

test('§8.7 block 3 — analytics: Clarity, Azure storage, retention, masking and the Microsoft statement', function () {
    expectPrivacyBlock([
        'Analytics',
        'Microsoft Clarity',
        'Microsoft Azure',
        '30 days',
        'nine months',
        'Masking',
        'https://privacy.microsoft.com/privacystatement',
    ]);
});

test('§8.7 block 4 — advertising: AdSense on article pages only, non-personalised', function () {
    expectPrivacyBlock([
        'Advertising',
        'Google AdSense',
        'individual article page',
        'non-personalised',
    ]);
});

test('§8.7 block 5 — the legal bases and the DNT/GPC statement', function () {
    expectPrivacyBlock([
        'Why this site may process your data',
        'Consent',
        'Legitimate interests',
        'Legal obligation',
        'Do Not Track',
        'Global Privacy Control',
        'Neither signal ever',
    ]);
});

test('§8.7 block 6 — the retention table', function () {
    expectPrivacyBlock([
        'How long your data is kept',
        'Consent record (the consent cookie)',
        '180 days',
        '120 minutes of inactivity',
        'Microsoft Clarity recordings',
        'Up to 9 months',
    ]);
});

test('§8.7 block 7 — the six rights, the California statement and how to exercise each', function () {
    expectPrivacyBlock([
        'Your rights',
        'Access',
        'Rectification',
        'Erasure',
        'Restriction',
        'Objection',
        'Portability',
        'If you are in California',
        'does not sell or share personal information',
        'How to make a request',
        'What withdrawal cannot reach',
    ]);
});

test('§8.7 block 8 — children', function () {
    expectPrivacyBlock([
        'Children',
        'not directed at',
        'under 18',
    ]);
});

test('§8.7 block 9 — the #choices anchor', function () {
    expectPrivacyBlock([
        'id="choices"',
        'Your choices',
    ]);
});

test('§8.7 block 10 — the accessibility statement', function () {
    expectPrivacyBlock([
        'How this page is built',
    ]);
});

test('the policy renders real tables with captions and scoped headers', function () {
    $source = privacyPolicySource();

    expect(substr_count($source, '<table'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($source, '<caption'))->toBeGreaterThanOrEqual(2)
        ->and($source)->toContain('scope="col"')
        ->and($source)->toContain('scope="row"');
});

test('the DNT and GPC statement says neither signal grants consent', function () {
    $source = privacyPolicySource();

    expect($source)->toContain('Do Not Track')
        ->and($source)->toContain('Global Privacy Control')
        ->and($source)->toContain('Sec-GPC')
        ->and($source)->toContain('Neither signal ever');
});

test('the required Google partner-sites link is present', function () {
    expect(privacyPolicySource())
        ->toContain('https://policies.google.com/technologies/partner-sites');
});
