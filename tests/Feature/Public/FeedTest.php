<?php

use App\Models\Profile;
use App\Models\Writing;

/*
|--------------------------------------------------------------------------
| §3.9 — the Atom feed
|--------------------------------------------------------------------------
|
| `spatie/laravel-feed` serves `/writing/feed.atom` through `Route::feeds()`,
| which `routes/web.php` registers *before* `/writing/{writing:slug}` because
| both URIs are two segments deep (§3.2). Everything here is asserted against
| the Atom body; the feed is one of the five public responses that are not
| Inertia pages (§3.1 item 1).
|
| Content type note: the package maps Atom's *response* type to
| `application/xml` (`FeedContentType::$typeMap`); `application/atom+xml` is the
| type it uses for the `<link rel="alternate">` discovery element, not for the
| feed response itself. The assertions below pin what the package actually
| sends, and the body is checked for the Atom namespace so the document cannot
| silently become RSS.
|
*/

/**
 * The Atom entries of a feed body, in document order.
 *
 * `summary` is trimmed: `feed::atom` writes it on its own indented line inside
 * the element, so the parsed text node carries the template's whitespace.
 *
 * @return array<int, array{title: string, link: string, summary: string, author: string}>
 */
$feedEntries = function (string $body): array {
    $xml = simplexml_load_string($body);

    expect($xml)->not->toBeFalse();

    $xml->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');

    $entries = [];

    foreach ($xml->xpath('a:entry') as $entry) {
        $entries[] = [
            'title' => (string) $entry->title,
            'link' => (string) $entry->link['href'],
            'summary' => trim((string) $entry->summary),
            'author' => (string) $entry->author->name,
        ];
    }

    return $entries;
};

test('the Atom feed answers 200 with an XML content type and the Atom namespace', function () {
    config(['app.indexable' => true]);

    Profile::factory()->create(['name' => 'Ada Example']);

    Writing::factory()->published()->create([
        'title' => 'A published note',
        'published_at' => now()->subDay(),
    ]);

    $response = $this->get('/writing/feed.atom');

    $response->assertOk()->assertHeader('Content-Type', 'application/xml;charset=UTF-8');

    $body = (string) $response->getContent();

    expect($body)->toContain('<feed xmlns="http://www.w3.org/2005/Atom"')
        ->and($body)->toContain('<title><![CDATA[Writing]]></title>');
});

test('the feed lists published writings newest first', function () use ($feedEntries) {
    Profile::factory()->create(['name' => 'Ada Example']);

    Writing::factory()->published()->create(['title' => 'Oldest note', 'published_at' => now()->subDays(5)]);
    Writing::factory()->published()->create(['title' => 'Middle note', 'published_at' => now()->subDays(3)]);
    Writing::factory()->published()->create(['title' => 'Newest note', 'published_at' => now()->subDay()]);

    $titles = array_column($feedEntries((string) $this->get('/writing/feed.atom')->getContent()), 'title');

    expect($titles)->toBe(['Newest note', 'Middle note', 'Oldest note']);
});

test('the feed never lists a draft writing', function () use ($feedEntries) {
    Profile::factory()->create(['name' => 'Ada Example']);

    Writing::factory()->published()->create(['title' => 'Visible note', 'published_at' => now()->subDay()]);
    Writing::factory()->draft()->create(['title' => 'Hidden note']);

    $titles = array_column($feedEntries((string) $this->get('/writing/feed.atom')->getContent()), 'title');

    expect($titles)->toBe(['Visible note']);
});

test('the feed is capped at twenty items', function () use ($feedEntries) {
    Profile::factory()->create(['name' => 'Ada Example']);

    foreach (range(0, 24) as $index) {
        Writing::factory()->published()->create([
            'title' => sprintf('Note %02d', $index),
            'published_at' => now()->subDays(30 - $index),
        ]);
    }

    $titles = array_column($feedEntries((string) $this->get('/writing/feed.atom')->getContent()), 'title');

    expect($titles)->toHaveCount(20)
        ->and($titles[0])->toBe('Note 24')
        ->and($titles[19])->toBe('Note 05');

    // The five oldest writings are the ones dropped by the cap.
    expect($titles)->not->toContain('Note 04')
        ->and($titles)->not->toContain('Note 00');
});

test('each feed entry links to the writing URL and carries the excerpt and the author', function () use ($feedEntries) {
    Profile::factory()->create(['name' => 'Ada Example']);

    Writing::factory()->published()->create([
        'title' => 'A published note',
        'excerpt' => 'A short field note about backlogs.',
        'published_at' => now()->subDay(),
    ]);

    $entries = $feedEntries((string) $this->get('/writing/feed.atom')->getContent());

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['link'])->toEndWith('/writing/a-published-note')
        ->and($entries[0]['summary'])->toBe('A short field note about backlogs.')
        ->and($entries[0]['author'])->toBe('Ada Example');
});

test('the feed still works when config app.indexable is false', function () use ($feedEntries) {
    config(['app.indexable' => false]);

    Profile::factory()->create(['name' => 'Ada Example']);

    Writing::factory()->published()->create(['title' => 'A published note', 'published_at' => now()->subDay()]);

    $response = $this->get('/writing/feed.atom');

    $response->assertOk();

    // The feed is a reader feature, not a crawler feature: unlike the sitemap it
    // does not collapse when the site is not indexable (§3.9).
    expect(array_column($feedEntries((string) $response->getContent()), 'title'))->toBe(['A published note']);
});
