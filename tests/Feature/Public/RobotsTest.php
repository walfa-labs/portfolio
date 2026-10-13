<?php

/*
|--------------------------------------------------------------------------
| §3.9 — robots.txt
|--------------------------------------------------------------------------
|
| `public/robots.txt` is deleted so `Public\RobotsController` can emit an
| absolute `Sitemap:` line from `config('app.url')` — a static file has no idea
| what origin it is served from (§3.2, §3.9). The body is small and fixed, so
| these tests compare the whole document line by line rather than probing it
| with `assertSee`: `assertSee('Disallow: /')` would also match
| `Disallow: /admin`, which is exactly the distinction the collapse test is
| about.
|
*/

/**
 * The document as a list of non-empty lines.
 *
 * @return list<string>
 */
$robotsLines = function (string $body): array {
    return array_values(array_filter(
        preg_split('/\R/', trim($body)) ?: [],
        fn (string $line): bool => trim($line) !== '',
    ));
};

test('robots.txt serves the §3.9 directive set while the site is indexable', function () use ($robotsLines) {
    config(['app.indexable' => true, 'app.url' => 'https://portfolio.example']);

    $response = $this->get('/robots.txt');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    // Symfony's header bag canonicalises the directive order, so the value is
    // asserted as a directive set rather than as the §3.9 literal string.
    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('max-age=3600');

    expect($robotsLines((string) $response->getContent()))->toBe([
        'User-agent: *',
        'Disallow: /admin',
        'Disallow: /settings',
        'Disallow: /api/',
        'Disallow: /search',
        'Disallow: /*?q=',
        'Sitemap: https://portfolio.example/sitemap.xml',
    ]);

    // The catch-all line is what the collapse produces; it must not appear in
    // the indexable document, where only the five prefixes are disallowed.
    expect($robotsLines((string) $response->getContent()))->not->toContain('Disallow: /');
});

test('the Sitemap line is built from config app.url and never from the request host', function () {
    config(['app.indexable' => true, 'app.url' => 'https://portfolio.example']);

    $body = (string) $this->get('https://attacker.example/robots.txt')->getContent();

    expect($body)->toContain('Sitemap: https://portfolio.example/sitemap.xml')
        ->and($body)->not->toContain('attacker.example');
});

test('robots.txt collapses to Disallow: / when config app.indexable is false', function () use ($robotsLines) {
    config(['app.indexable' => false, 'app.url' => 'https://portfolio.example']);

    $response = $this->get('/robots.txt');

    $response->assertOk();

    expect($robotsLines((string) $response->getContent()))->toBe([
        'User-agent: *',
        'Disallow: /',
        // The sitemap route stays the canonical location for the document even
        // while the crawler directives tell every bot to stay away (§3.9).
        'Sitemap: https://portfolio.example/sitemap.xml',
    ]);
});

test('the static public/robots.txt is gone so the route can serve the document', function () {
    expect(file_exists(public_path('robots.txt')))->toBeFalse();
});
