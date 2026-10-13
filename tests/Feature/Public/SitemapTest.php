<?php

use App\Models\Tag;
use App\Models\Writing;

/*
|--------------------------------------------------------------------------
| §3.9 — the XML sitemap
|--------------------------------------------------------------------------
|
| `/sitemap.xml` renders `resources/views/sitemap.blade.php` and is one of the
| five public responses that are deliberately not Inertia pages (§3.1 item 1),
| so everything here is asserted against the XML body.
|
| `<loc>` values are matched on their path rather than their full URL, so the
| per-route tests below cannot catch a `<loc>` built from the request host. That
| is a real defect rather than an implementation detail: an absolute `route()`
| takes its origin from the `Host` header, and the document is cached on a
| constant key, so the first host to hit `/sitemap.xml` would fix the origin for
| every later visitor. The controller now builds every `<loc>` from
| `config('app.url')` through `StructuredData::url()`, and the spoofed-Host test
| below is what pins that down.
|
*/

/**
 * Parse a sitemap body into `loc => lastmod` plus a `path => loc` lookup.
 *
 * @return array{urls: array<string, string|null>, paths: array<string, string>}
 */
$parseSitemap = function (string $body): array {
    $xml = simplexml_load_string($body);

    expect($xml)->not->toBeFalse();

    $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    $urls = [];
    $paths = [];

    foreach ($xml->xpath('sm:url') as $url) {
        $loc = (string) $url->loc;
        $urls[$loc] = isset($url->lastmod) ? (string) $url->lastmod : null;
        $paths[parse_url($loc, PHP_URL_PATH) ?: '/'] = $loc;
    }

    return ['urls' => $urls, 'paths' => $paths];
};

test('the sitemap answers 200 with the XML content type and an hour of public caching', function () {
    config(['app.indexable' => true]);

    $response = $this->get('/sitemap.xml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    // Symfony's header bag canonicalises the directive order, so the value is
    // asserted as a directive set rather than as the §3.9 literal string.
    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('max-age=3600');

    expect($response->getContent())->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
});

test('the sitemap lists every published writing with its lastmod', function () use ($parseSitemap) {
    config(['app.indexable' => true]);

    $first = Writing::factory()->published()->create([
        'title' => 'First published note',
        'published_at' => now()->subDays(3),
    ]);

    $second = Writing::factory()->published()->create([
        'title' => 'Second published note',
        'published_at' => now()->subDay(),
    ]);

    ['urls' => $urls, 'paths' => $paths] = $parseSitemap((string) $this->get('/sitemap.xml')->getContent());

    expect($paths)->toHaveKeys(['/writing/first-published-note', '/writing/second-published-note']);

    expect($urls[$paths['/writing/first-published-note']])
        ->toBe($first->fresh()->updated_at->toAtomString())
        ->and($urls[$paths['/writing/second-published-note']])
        ->toBe($second->fresh()->updated_at->toAtomString());
});

test('the sitemap never lists a draft writing', function () use ($parseSitemap) {
    config(['app.indexable' => true]);

    Writing::factory()->published()->create([
        'title' => 'Visible note',
        'published_at' => now()->subDay(),
    ]);

    Writing::factory()->draft()->create(['title' => 'Hidden note']);

    ['paths' => $paths] = $parseSitemap((string) $this->get('/sitemap.xml')->getContent());

    expect($paths)->toHaveKey('/writing/visible-note')
        ->and($paths)->not->toHaveKey('/writing/hidden-note');
});

test('the sitemap lists a tag URL only while the tag carries a published item', function () use ($parseSitemap) {
    config(['app.indexable' => true]);

    $published = Tag::factory()->create(['name' => 'Published Tag']);
    $draftOnly = Tag::factory()->create(['name' => 'Draft Tag']);
    $orphan = Tag::factory()->create(['name' => 'Orphan Tag']);

    $writing = Writing::factory()->published()->create([
        'title' => 'Tagged note',
        'published_at' => now()->subDay(),
    ]);

    $writing->tags()->attach($published);

    $draft = Writing::factory()->draft()->create(['title' => 'Untagged note']);
    $draft->tags()->attach($draftOnly);

    ['paths' => $paths] = $parseSitemap((string) $this->get('/sitemap.xml')->getContent());

    expect($paths)->toHaveKey('/tags/published-tag')
        ->and($paths)->not->toHaveKey('/tags/draft-tag')
        ->and($paths)->not->toHaveKey('/tags/orphan-tag');

    expect($orphan->taggables()->count())->toBe(0);
});

test('the sitemap lists the static public routes with their §3.9 priorities', function () {
    config(['app.indexable' => true]);

    $xml = simplexml_load_string((string) $this->get('/sitemap.xml')->getContent());
    $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    $priorities = [];

    foreach ($xml->xpath('sm:url') as $url) {
        $priorities[parse_url((string) $url->loc, PHP_URL_PATH) ?: '/'] = (string) $url->priority;
    }

    expect($priorities)->toMatchArray([
        '/' => '1.0',
        '/cv' => '0.9',
        '/experience' => '0.8',
        '/education' => '0.8',
        '/certifications' => '0.8',
        '/publications' => '0.8',
        '/projects' => '0.8',
        '/awards' => '0.8',
        '/writing' => '0.8',
        '/tags' => '0.5',
        // `/search` is listed despite the `Disallow` in robots.txt so the URL is
        // known to a crawler that reads the sitemap first (§3.9).
        '/search' => '0.5',
        '/contact' => '0.5',
        '/privacy' => '0.5',
    ]);
});

test('every sitemap loc uses the configured origin and never the request host', function () use ($parseSitemap) {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $writing = Writing::factory()->published()->create([
        'title' => 'Host probe',
        'published_at' => now()->subDay(),
    ]);

    $tag = Tag::factory()->create(['name' => 'Host Probe Tag']);
    $writing->tags()->attach($tag);

    // The request arrives on a host we do not control. Every `<loc>` must still
    // come from `config('app.url')`, because the document is cached on a
    // constant key and the first host to render it would otherwise win (§3.9).
    $response = $this->get('https://attacker.example/sitemap.xml');

    $response->assertOk();

    ['urls' => $urls] = $parseSitemap((string) $response->getContent());

    expect($urls)->not->toBeEmpty();

    foreach (array_keys($urls) as $loc) {
        expect($loc)->toStartWith('https://portfolio.example')
            ->and($loc)->not->toContain('attacker.example');
    }

    expect(array_keys($urls))->toContain('https://portfolio.example/writing/host-probe');
});

test('publishing a new writing flushes the cached sitemap', function () use ($parseSitemap) {
    config(['app.indexable' => true]);

    ['paths' => $before] = $parseSitemap((string) $this->get('/sitemap.xml')->getContent());

    expect($before)->not->toHaveKey('/writing/after-the-flush');

    // The document is cached for an hour from the request above; the content
    // observer must drop that entry so the new URL appears immediately (§3.9).
    Writing::factory()->published()->create([
        'title' => 'After the flush',
        'published_at' => now()->subDay(),
    ]);

    ['paths' => $after] = $parseSitemap((string) $this->get('/sitemap.xml')->getContent());

    expect($after)->toHaveKey('/writing/after-the-flush');
});

test('the sitemap collapses to an empty urlset when config app.indexable is false', function () {
    config(['app.indexable' => false]);

    Writing::factory()->published()->create([
        'title' => 'Unlisted note',
        'published_at' => now()->subDay(),
    ]);

    $response = $this->get('/sitemap.xml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<urlset', false)
        ->assertDontSee('<url>', false)
        ->assertDontSee('/writing/unlisted-note', false);

    $xml = simplexml_load_string((string) $response->getContent());
    $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    expect($xml->xpath('sm:url'))->toBe([]);
});
