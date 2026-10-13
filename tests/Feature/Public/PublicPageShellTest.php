<?php

use App\Models\Profile;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Writing;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The accessibility shell invariants (§11.3, §11.4)
|--------------------------------------------------------------------------
|
| §11.3 lists five kernel-level shell invariants and asks for them on every
| public page route:
|
|   1. `<html lang="en">` present
|   2. `<meta name="viewport">` present
|   3. exactly one `<h1>`
|   4. a skip link that is the first focusable element
|   5. a `<main>` landmark
|
| **What the kernel can actually see, and why.** These tests run without SSR:
| `config('inertia.ssr.enabled')` is true but `inertia.ssr.ensure_bundle_exists`
| (default true) finds no bundle — `bootstrap/ssr/` is empty and there is no
| `public/js/ssr.js` — so `Inertia\Ssr\HttpGateway::dispatch()` returns `null`
| before it ever opens a socket. `Inertia\View\Components\App` then renders
| `<script data-page="app" type="application/json">…</script><div id="app"></div>`
| and the response body is the **client-side shell**, not the finished page.
|
| That splits the five invariants in two:
|
|   * (1), (2) and (4) live in `resources/views/app.blade.php` — the Blade root
|     view every public page is rendered through — so they are present in the
|     HTTP response and are asserted below on every route.
|   * (3) and (5) live in Vue: the `<h1>` is rendered by each page component
|     (`<Heading as="h1">`) and the `<main id="main-content">` landmark by
|     `resources/js/layouts/PublicLayout.vue`. Neither exists in the kernel
|     document without SSR, and `@vue/test-utils` is not installed (§14 Q11), so
|     there is no component-level way to assert them either. They are covered by
|     the §11.6 browser sweep (axe's `page-has-heading-one` and `region` rules)
|     and by nothing in CI — the CI gap §11.3 states plainly. What this file can
|     assert instead is the kernel-level precondition for both: every public
|     route resolves to a `public/*` component, which is the branch of the
|     layout resolver in `resources/js/app.ts` that wraps the page in
|     `PublicLayout` — the component that renders `<main id="main-content">`,
|     the skip link's target.
|
| The sweep covers the fifteen §3.2 page routes plus the 404 error page, which
| renders through the same root view. The five non-page responses are excluded
| on purpose: `/sitemap.xml`, `/robots.txt`, `/writing/feed.atom` and
| `/cv/download` are not HTML documents at all.
|
*/

/**
 * The `<body>` of a rendered document, without its attributes.
 */
function publicShellBody(string $html): string
{
    preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $matches);

    return $matches[1] ?? '';
}

/**
 * The skip link as it appears in the document, or an empty string when absent.
 */
function publicShellSkipLink(string $body): string
{
    preg_match('/<a\b[^>]*href="#main-content"[^>]*>/i', $body, $matches);

    return $matches[0] ?? '';
}

/**
 * Create the smallest corpus that lets every public page route answer 200.
 *
 * The shell invariants do not depend on the content, so this is deliberately
 * thinner than the route walk's fixture — just enough that no page 404s.
 */
function seedPublicShellFixtures(): void
{
    Profile::factory()->create([
        'name' => 'Ada Fixture',
        'role_headline' => 'Software Engineer',
        'positioning_sentence' => 'I build and maintain web applications.',
    ]);

    $writing = Writing::factory()->published()->create([
        'title' => 'Fixture Writing',
        'body' => "## A heading\n\nHello world.",
        'published_at' => '2024-06-01 09:00:00',
    ]);

    expect($writing->slug)->toBe('fixture-writing');

    $project = Project::factory()->published()->featured()->create(['title' => 'Featured Project One']);

    $tag = Tag::factory()->create(['name' => 'Laravel']);

    expect($tag->slug)->toBe('laravel');

    $project->tags()->attach($tag);
}

dataset('public shell routes', [
    'home' => ['/', 'public/Home'],
    'experience.index' => ['/experience', 'public/Experience'],
    'education.index' => ['/education', 'public/Education'],
    'certifications.index' => ['/certifications', 'public/Certifications'],
    'publications.index' => ['/publications', 'public/Publications'],
    'projects.index' => ['/projects', 'public/Projects'],
    'awards.index' => ['/awards', 'public/Awards'],
    'writing.index' => ['/writing', 'public/Writing'],
    'writing.show' => ['/writing/fixture-writing', 'public/WritingShow'],
    'tags.index' => ['/tags', 'public/Tags'],
    'tags.show' => ['/tags/laravel', 'public/Tag'],
    'cv' => ['/cv', 'public/Cv'],
    'search' => ['/search', 'public/Search'],
    'contact.show' => ['/contact', 'public/Contact'],
    'privacy' => ['/privacy', 'public/Privacy'],
]);

test('every public page route carries the kernel-level accessibility shell', function (string $uri, string $component) {
    seedPublicShellFixtures();

    $response = $this->get($uri)->assertOk();

    $html = (string) $response->getContent();

    // Invariant 1 — the document declares its language. `app.blade.php` builds
    // it from `app()->getLocale()`, so a locale regression fails here.
    expect($html)->toMatch('/<html\b[^>]*\slang="en"/');

    // Invariant 2 — the viewport meta, without which every mobile a11y check is
    // meaningless (the page would render at desktop width and be zoomed out).
    expect($html)->toMatch('/<meta\s+name="viewport"\s+content="width=device-width, initial-scale=1"/');

    $body = publicShellBody($html);

    expect($body)->not->toBe('');

    // Invariant 4 — the skip link exists, targets the layout's `<main>`, and is
    // the first focusable element: nothing before it in `<body>` is a link,
    // button, form control or a positive/zero tabindex.
    $skipLink = publicShellSkipLink($body);

    expect($skipLink)->not->toBe('')
        ->and($skipLink)->toContain('skip-link');

    $position = strpos($body, $skipLink);

    expect($position)->not->toBeFalse();

    $before = substr($body, 0, (int) $position);

    expect($before)->not->toMatch('/<(a|button|input|select|textarea)\b/i')
        ->and($before)->not->toMatch('/\stabindex="(?:0|[1-9][0-9]*)"/i');

    // The Inertia root is present, so the shell is a document a client can
    // hydrate — and the route resolves to the `public/*` branch of the layout
    // resolver, which is what wraps the page in `PublicLayout` (the `<main>`
    // landmark, and the skip link's target). See the file header for why the
    // landmark itself is not assertable here.
    expect($body)->toMatch('/<div\b[^>]*id="app"/');

    $response->assertInertia(fn (Assert $page) => $page->component($component));

    expect($component)->toStartWith('public/');
})->with('public shell routes');

test('the 404 error page renders through the same accessible shell', function () {
    seedPublicShellFixtures();

    $html = (string) $this->get('/this-page-does-not-exist')->assertNotFound()->getContent();

    expect($html)->toMatch('/<html\b[^>]*\slang="en"/')
        ->and($html)->toMatch('/<meta\s+name="viewport"\s+content="width=device-width, initial-scale=1"/');

    $body = publicShellBody($html);

    expect(publicShellSkipLink($body))->not->toBe('')
        ->and($body)->toMatch('/<div\b[^>]*id="app"/');
});
