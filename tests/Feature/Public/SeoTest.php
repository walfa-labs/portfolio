<?php

use App\Models\Profile;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| §3.9 — SEO per route type
|--------------------------------------------------------------------------
|
| **Why this file asserts props and not `<head>` tags.** The suite runs without
| SSR, so the document head is produced client-side by `SeoHead.vue` and is not
| present in the HTTP response — `assertSee('<meta name="description"')` would
| fail against a head that has not been rendered yet, not against a broken head.
| Every assertion below therefore reads the Inertia props the head renders from:
| `title`, `description`, `canonical`, `jsonLd` and `noindex`. The server owns
| all five, so the props *are* the SEO contract at kernel level; what cannot be
| asserted here is the tag-by-tag rendering, which is `SeoHead.vue`'s job.
|
| **The owner's name is never hardcoded.** `APP_NAME` is an explicit placeholder
| and the real name is still an open question (§14), so every title assertion is
| written against `config('app.name')`, and one test proves the pattern follows
| that config key rather than a literal.
|
| `config('app.indexable')` is set explicitly in every test that depends on it:
| it defaults to `false` (§3.1 item 5) but reads `APP_INDEXABLE` from `.env`,
| which is developer state, so relying on the ambient value would make these
| tests machine-dependent.
|
*/

test('the home page titles the document with the configured name and the profile role', function () {
    Profile::factory()->create([
        'role_headline' => 'Software Engineer',
        'positioning_sentence' => 'I build small, observable systems.',
    ]);

    config(['app.url' => 'https://portfolio.example', 'app.indexable' => false]);

    $response = $this->get('/');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/Home')
        ->hasAll(['title', 'description', 'canonical', 'jsonLd', 'noindex'])
        ->where('title', config('app.name').' — Software Engineer')
        ->where('description', 'I build small, observable systems.')
        ->where('canonical', 'https://portfolio.example/')
        ->where('noindex', true));

    $graph = data_get($response->viewData('page'), 'props.jsonLd.0.@graph');

    expect(collect($graph)->pluck('@type')->all())
        ->toContain('Person')
        ->toContain('WebSite')
        ->toContain('ProfilePage');

    $profilePage = collect($graph)->firstWhere('@type', 'ProfilePage');

    expect($profilePage['mainEntity']['@id'])->toBe('https://portfolio.example#person');
});

test('the home description is the positioning sentence truncated to 155 characters', function () {
    // `profile.positioning_sentence` is varchar(160), so the fixture has to fit the
    // column while still running past the 155 characters the description is cut to.
    // SQLite ignores a varchar's length; MariaDB rejects anything longer.
    $sentence = Str::limit(str_repeat('Reliable systems, clearly explained. ', 8), 160, '');

    expect(mb_strlen($sentence))->toBeGreaterThan(155);

    Profile::factory()->create([
        'role_headline' => 'Software Engineer',
        'positioning_sentence' => $sentence,
    ]);

    $description = $this->get('/')->viewData('page')['props']['description'];

    expect($description)
        ->toBe(rtrim(mb_substr($sentence, 0, 154)).'…')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(155);
});

test('each content list carries its §3.9 title pattern and a CollectionPage graph', function (string $path, string $section) {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $response = $this->get($path);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('title', $section.' - '.config('app.name'))
        ->where('canonical', 'https://portfolio.example'.$path));

    $props = $response->viewData('page')['props'];

    expect($props['description'])->toBeString()->not->toBe('');

    expect(collect(data_get($props, 'jsonLd.0.@graph'))->pluck('@type')->all())
        ->toContain('CollectionPage')
        ->toContain('ItemList')
        ->toContain('BreadcrumbList')
        ->toContain('Person')
        ->toContain('WebSite');
})->with([
    ['/experience', 'Experience'],
    ['/education', 'Education'],
    ['/certifications', 'Certifications'],
    ['/publications', 'Publications'],
    ['/projects', 'Projects'],
    ['/awards', 'Awards'],
    ['/writing', 'Writing'],
]);

test('the experience description counts every published role and names the most recent one', function () {
    WorkExperience::factory()->published()->create([
        'title' => 'Lead Engineer',
        'employer' => 'Acme Systems',
        'start_date' => '2021-01-01',
        'end_date' => null,
    ]);

    WorkExperience::factory()->published()->create([
        'title' => 'Backend Engineer',
        'employer' => 'Older Co',
        'start_date' => '2018-01-01',
        'end_date' => '2020-12-31',
    ]);

    WorkExperience::factory()->draft()->create([
        'title' => 'Draft Role',
        'employer' => 'Never Seen',
        'start_date' => '2024-01-01',
        'end_date' => null,
    ]);

    $this->get('/experience')->assertInertia(fn (Assert $page) => $page
        ->where('title', 'Experience - '.config('app.name'))
        ->where('description', '2 roles, most recently Lead Engineer at Acme Systems.'));
});

test('the writing detail page carries the post title, its excerpt and a BlogPosting graph', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    Writing::factory()->published()->create([
        'title' => 'On queue backlogs',
        'excerpt' => 'A short field note about backlogs.',
        'published_at' => now()->subDay(),
    ]);

    $response = $this->get('/writing/on-queue-backlogs');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/WritingShow')
        ->where('title', 'On queue backlogs - '.config('app.name'))
        ->where('description', 'A short field note about backlogs.')
        ->where('canonical', 'https://portfolio.example/writing/on-queue-backlogs'));

    $graph = data_get($response->viewData('page'), 'props.jsonLd.0.@graph');

    $posting = collect($graph)->firstWhere('@type', 'BlogPosting');

    expect($posting)->not->toBeNull()
        ->and($posting['headline'])->toBe('On queue backlogs')
        ->and($posting['mainEntityOfPage']['@id'])->toBe('https://portfolio.example/writing/on-queue-backlogs')
        ->and($posting['datePublished'])->not->toBeNull();

    $crumbs = collect($graph)->firstWhere('@type', 'BreadcrumbList');

    expect(collect($crumbs['itemListElement'])->pluck('name')->all())
        ->toBe(['Home', 'Writing', 'On queue backlogs']);
});

test('the tag page titles itself with the hashed slug and describes its published items', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $tag = Tag::factory()->create(['name' => 'Laravel']);

    $writing = Writing::factory()->published()->create([
        'title' => 'A tagged note',
        'published_at' => now()->subDay(),
    ]);

    $writing->tags()->attach($tag);

    $this->get('/tags/laravel')->assertInertia(fn (Assert $page) => $page
        ->component('public/Tag')
        ->where('title', '#laravel - '.config('app.name'))
        ->where('description', '1 item tagged #laravel across writings.')
        ->where('canonical', 'https://portfolio.example/tags/laravel'));
});

test('/tags describes how many topics exist', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    Tag::factory()->count(3)->create();

    $this->get('/tags')->assertInertia(fn (Assert $page) => $page
        ->component('public/Tags')
        ->where('title', 'Tags - '.config('app.name'))
        ->where('description', 'Browse all 3 topics.')
        ->where('canonical', 'https://portfolio.example/tags'));
});

test('/search is always noindex and carries the X-Robots-Tag header and a SearchResultsPage graph', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $response = $this->get('/search');

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Search')
            ->where('title', 'Search - '.config('app.name'))
            ->where('description', 'Search this site.')
            ->where('canonical', 'https://portfolio.example/search')
            ->where('noindex', true));

    expect(collect(data_get($response->viewData('page'), 'props.jsonLd.0.@graph'))->pluck('@type')->all())
        ->toContain('SearchResultsPage');
});

test('/cv is noindex by default and carries a ProfilePage graph', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => false]);

    $response = $this->get('/cv');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/Cv')
        ->where('title', 'CV - '.config('app.name'))
        ->where('description', 'Full curriculum vitae: experience, education, publications and projects.')
        ->where('canonical', 'https://portfolio.example/cv')
        ->where('noindex', true));

    $profilePage = collect(data_get($response->viewData('page'), 'props.jsonLd.0.@graph'))
        ->firstWhere('@type', 'ProfilePage');

    expect($profilePage)->not->toBeNull()
        ->and($profilePage['mainEntity']['@id'])->toBe('https://portfolio.example#person');
});

test('/contact carries the §3.9 title, description and ContactPage graph', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $response = $this->get('/contact');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/Contact')
        ->where('title', 'Contact - '.config('app.name'))
        ->where('description', 'Get in touch with '.config('app.name').'.')
        ->where('canonical', 'https://portfolio.example/contact'));

    expect(collect(data_get($response->viewData('page'), 'props.jsonLd.0.@graph'))->pluck('@type')->all())
        ->toContain('ContactPage');
});

test('/privacy carries the §3.9 title, description and WebPage graph', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $response = $this->get('/privacy');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/Privacy')
        ->where('title', 'Privacy - '.config('app.name'))
        ->where('description', 'How this site handles your data.')
        ->where('canonical', 'https://portfolio.example/privacy'));

    expect(collect(data_get($response->viewData('page'), 'props.jsonLd.0.@graph'))->pluck('@type')->all())
        ->toContain('WebPage');
});

test('every public page is indexable when config app.indexable is true', function (string $path) {
    config(['app.indexable' => true]);

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('noindex', false));
})->with([
    '/',
    '/experience',
    '/education',
    '/certifications',
    '/publications',
    '/projects',
    '/awards',
    '/writing',
    '/cv',
    '/tags',
    '/contact',
    '/privacy',
]);

test('every public page emits noindex when config app.indexable is false', function (string $path) {
    config(['app.indexable' => false]);

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('noindex', true));
})->with([
    '/',
    '/experience',
    '/education',
    '/certifications',
    '/publications',
    '/projects',
    '/awards',
    '/writing',
    '/cv',
    '/tags',
    '/search',
    '/contact',
    '/privacy',
]);

test('a writing detail page and a tag page follow the same indexable switch', function () {
    $writing = Writing::factory()->published()->create([
        'title' => 'Indexable probe',
        'published_at' => now()->subDay(),
    ]);

    $tag = Tag::factory()->create(['name' => 'Indexable probe']);
    $writing->tags()->attach($tag);

    config(['app.indexable' => false]);

    $this->get('/writing/indexable-probe')->assertInertia(fn (Assert $page) => $page->where('noindex', true));
    $this->get('/tags/indexable-probe')->assertInertia(fn (Assert $page) => $page->where('noindex', true));

    config(['app.indexable' => true]);

    $this->get('/writing/indexable-probe')->assertInertia(fn (Assert $page) => $page->where('noindex', false));
    $this->get('/tags/indexable-probe')->assertInertia(fn (Assert $page) => $page->where('noindex', false));
});

test('canonical URLs come from config app.url and never from the request host', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $this->get('https://attacker.example/experience')
        ->assertInertia(fn (Assert $page) => $page->where('canonical', 'https://portfolio.example/experience'));

    $this->get('http://attacker.example/')
        ->assertInertia(fn (Assert $page) => $page->where('canonical', 'https://portfolio.example/'));
});

test('titles follow config app.name rather than a fixed owner name', function () {
    config(['app.name' => 'Ada Example', 'app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    Profile::factory()->create(['role_headline' => 'Software Engineer']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('title', 'Ada Example — Software Engineer'));
    $this->get('/experience')->assertInertia(fn (Assert $page) => $page->where('title', 'Experience - Ada Example'));
    $this->get('/contact')->assertInertia(fn (Assert $page) => $page->where('title', 'Contact - Ada Example'));
    $this->get('/tags')->assertInertia(fn (Assert $page) => $page->where('title', 'Tags - Ada Example'));
});

test('every public page returns a non-empty meta description', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $writing = Writing::factory()->published()->create([
        'title' => 'Description probe',
        'published_at' => now()->subDay(),
    ]);

    $tag = Tag::factory()->create(['name' => 'Description probe']);
    $writing->tags()->attach($tag);

    $paths = [
        '/',
        '/experience',
        '/education',
        '/certifications',
        '/publications',
        '/projects',
        '/awards',
        '/writing',
        '/writing/description-probe',
        '/cv',
        '/tags',
        '/tags/description-probe',
        '/search',
        '/contact',
        '/privacy',
    ];

    foreach ($paths as $path) {
        $response = $this->get($path);

        $response->assertOk();

        $description = $response->viewData('page')['props']['description'];

        $this->assertIsString($description, "{$path} returned a non-string meta description");
        $this->assertNotSame('', trim($description), "{$path} returned an empty meta description");
    }
});

test('every public page carries the Person and WebSite graph members', function (string $path) {
    config(['app.indexable' => true]);

    $types = collect(data_get($this->get($path)->viewData('page'), 'props.jsonLd.0.@graph'))->pluck('@type')->all();

    expect($types)->toContain('Person')->toContain('WebSite');
})->with([
    '/',
    '/experience',
    '/education',
    '/certifications',
    '/publications',
    '/projects',
    '/awards',
    '/writing',
    '/cv',
    '/tags',
    '/search',
    '/contact',
    '/privacy',
]);

/*
 * §3.1 item 6 pins page ≥ 2 of a paginated list as self-canonical and
 * `noindex,follow`. The `noindex` boolean still marks the page unindexable; the
 * `robots` string carries the `follow` half the boolean cannot express, so the
 * page is both self-canonical and `noindex,follow` end to end.
 */
test('page two of a paginated list is self-canonical and noindex', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $this->get('/experience?page=2')->assertInertia(fn (Assert $page) => $page
        ->where('canonical', 'https://portfolio.example/experience?page=2')
        ->where('noindex', true)
        ->where('robots', 'noindex,follow'));
});

test('page two of every paginated list sends the noindex,follow robots prop', function (string $path) {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('noindex', true)
        ->where('robots', 'noindex,follow'));
})->with([
    '/experience?page=2',
    '/projects?page=2',
    '/publications?page=2',
    '/writing?page=2',
]);

test('page two of a tag list sends the noindex,follow robots prop', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    Tag::factory()->create(['name' => 'Robots Probe']);

    $this->get('/tags/robots-probe?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canonical', 'https://portfolio.example/tags/robots-probe?page=2')
        ->where('noindex', true)
        ->where('robots', 'noindex,follow'));
});

test('page one and an unpaginated page send no robots prop, so the boolean governs', function (string $path) {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('robots')
        ->where('noindex', false));
})->with([
    '/experience',
    '/projects',
    '/writing',
    '/',
    '/contact',
]);

/*
 * §3.8 makes the search page itself `noindex,follow` while §3.9 pins its
 * `X-Robots-Tag` header to `noindex, nofollow`. The PRD contradicts itself; the
 * header is specified verbatim, so it stays `nofollow` while the meta tag
 * follows. This test asserts both halves of that deliberate asymmetry.
 */
test('/search sends noindex,follow in the meta while the header stays nofollow', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    $this->get('/search')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page
            ->where('noindex', true)
            ->where('robots', 'noindex,follow'));
});

/*
 * The tests above pin each axis on its own — `app.indexable` true or false on the
 * one hand, page 1 versus page ≥ 2 on the other — but never the combination,
 * and the combination is where the bug lived. §3.1 item 5 makes every page of a
 * non-indexable site `noindex,nofollow`, and that site-wide switch outranks
 * item 6's `noindex,follow` on page ≥ 2 and §3.8's on `/search`. The `robots`
 * prop wins over the `noindex` boolean in `SeoHead.vue` whenever it is present,
 * so on a placeholder site those pages must send no prop at all: a prop that
 * survives the switch leaks the `follow` half onto a site that forbids it. The
 * indexable half is asserted too, so the gate is proven to be `app.indexable`
 * rather than the prop simply disappearing.
 */
test('on a non-indexable site page two of every paginated list sends no robots prop', function (string $path) {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => false]);

    Tag::factory()->create(['name' => 'Robots Probe']);

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('noindex', true)
        ->missing('robots'));
})->with([
    '/experience?page=2',
    '/projects?page=2',
    '/publications?page=2',
    '/writing?page=2',
    '/tags/robots-probe?page=2',
]);

test('on a non-indexable site /search sends no robots prop', function () {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => false]);

    $this->get('/search')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page
            ->where('noindex', true)
            ->missing('robots'));
});

test('on an indexable site page two of every paginated list and /search send the noindex,follow robots prop', function (string $path) {
    config(['app.url' => 'https://portfolio.example', 'app.indexable' => true]);

    Tag::factory()->create(['name' => 'Robots Probe']);

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('noindex', true)
        ->where('robots', 'noindex,follow'));
})->with([
    '/experience?page=2',
    '/projects?page=2',
    '/publications?page=2',
    '/writing?page=2',
    '/tags/robots-probe?page=2',
    '/search',
]);

/*
 * `og:image` is built client-side by `SeoHead.vue` from an `image` prop that
 * defaults to `og/default.png` (§3.9), so the server half of the contract is the
 * committed artefact itself plus the per-writing override the writing page hands
 * over. The tags — `og:type`, the `og:image:width`/`height` literals, the
 * `twitter:*` mirrors, `og:locale`, the feed discovery link — are all literals
 * inside `SeoHead.vue` and have no server-side prop to assert.
 */
test('the committed Open Graph placeholder is a 1200x630 PNG', function () {
    $path = public_path('og/default.png');

    expect(file_exists($path))->toBeTrue();

    $size = getimagesize($path);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(1200)
        ->and($size[1])->toBe(630)
        ->and($size['mime'])->toBe('image/png');
});

test('a writing with an og_image_path hands that override to the head', function () {
    Writing::factory()->published()->create([
        'title' => 'A note with a cover',
        'og_image_path' => 'media/covers/a-note-with-a-cover-1200x630.webp',
        'published_at' => now()->subDay(),
    ]);

    $this->get('/writing/a-note-with-a-cover')->assertInertia(fn (Assert $page) => $page
        ->where('writing.image', 'media/covers/a-note-with-a-cover-1200x630.webp'));
});

test('the writing detail page hands the head the article metadata it mirrors', function () {
    $writing = Writing::factory()->published()->create([
        'title' => 'Article metadata note',
        'published_at' => now()->subDays(2),
    ]);

    $tag = Tag::factory()->create(['name' => 'Observability']);
    $writing->tags()->attach($tag);

    $this->get('/writing/article-metadata-note')->assertInertia(fn (Assert $page) => $page
        ->where('writing.publishedAt', $writing->fresh()->published_at->toIso8601String())
        ->has('writing.updatedAt')
        ->where('writing.tags.0.name', 'Observability')
        ->where('writing.tags.0.slug', 'observability'));
});
