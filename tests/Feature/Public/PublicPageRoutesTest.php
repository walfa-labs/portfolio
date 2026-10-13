<?php

use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The §3.2 public page routes (§11.3, §11.4)
|--------------------------------------------------------------------------
|
| §11.3: this file walks every §3.2 route **that returns an Inertia page** —
| fifteen of them — asserting `200`, the component name and the props the page
| renders from. The five non-page responses have their own files and are
| deliberately absent here: `/sitemap.xml` (SitemapTest), `/robots.txt`
| (RobotsTest), `/writing/feed.atom` (FeedTest), `/cv/download`
| (CvDownloadTest) and `/media/{media}/{variant}` (Media\ServingTest, phase 8).
| `POST /contact` is a write and is covered by ContactTest.
|
| The fifteen page routes are:
|
|   /                public/Home          /tags/{tag:slug}  public/Tag
|   /experience      public/Experience    /cv               public/Cv
|   /education       public/Education     /tags             public/Tags
|   /certifications  public/Certifications /search          public/Search
|   /publications    public/Publications  /contact          public/Contact
|   /projects        public/Projects      /privacy          public/Privacy
|   /awards          public/Awards
|   /writing         public/Writing
|   /writing/{slug}  public/WritingShow
|
| Every fixture is created with explicit attributes, so no assertion depends on
| factory randomness or on row ordering the test did not specify.
|
| Ownership note: `SeoTest` owns title/description/canonical/OG/JSON-LD per
| route type, `HomeTest` owns the home composition, `WritingDetailTest` owns the
| detail page's Markdown, `TagFilterTest` owns the tag grouping and
| `SearchTest` owns the query behaviour. This file only proves that each route
| answers with the right page and hands it the props it reads.
|
*/

/**
 * Create the deterministic corpus every route in the walk renders from.
 *
 * Deliberately small: one row per content type, one current role, one featured
 * project, one tag attached to that project. A test that needs a second row
 * creates it locally.
 */
function seedPublicPageFixtures(): void
{
    Profile::factory()->create([
        'name' => 'Ada Fixture',
        'role_headline' => 'Software Engineer',
        'positioning_sentence' => 'I build and maintain web applications.',
        'location' => 'Bristol',
        'availability' => 'Open to contract work',
        'email' => 'ada@example.test',
        'github_url' => 'https://github.example.test/ada',
        'linkedin_url' => 'https://linkedin.example.test/in/ada',
        'orcid_url' => 'https://orcid.example.test/0000-0002-1825-0097',
        'years_experience_start' => '2016-01-01',
    ]);

    WorkExperience::factory()->published()->current()->create([
        'title' => 'Current Role',
        'employer' => 'Current Employer',
        'employer_url' => 'https://current-employer.example.test',
        'location' => 'Remote',
        'start_date' => '2016-01-01',
        'summary' => 'Leading the platform team.',
        'highlights' => ['Shipped the migration.'],
        'is_placeholder' => true,
    ]);

    Education::factory()->published()->create([
        'title' => 'BSc Computing',
        'institution' => 'Fixture University',
        'institution_url' => 'https://fixture-university.example.test',
        'field_of_study' => 'Computer Science',
        'start_date' => '2012-09-01',
        'end_date' => '2015-06-30',
        'is_placeholder' => true,
    ]);

    Certification::factory()->published()->create([
        'title' => 'Fixture Certification',
        'issuer' => 'Fixture Issuer',
        'credential_id' => 'FI-0001',
        'credential_url' => 'https://fixture-issuer.example.test/credentials/FI-0001',
        'issued_on' => '2024-03-01',
        'expires_on' => null,
        'is_placeholder' => true,
    ]);

    Publication::factory()->published()->create([
        'title' => 'Fixture Publication',
        'venue' => 'Fixture Venue',
        'authors' => 'A. Fixture',
        'doi' => '10.1234/fixture.2024.1',
        'issued_on' => '2024-05-01',
        'is_placeholder' => true,
    ]);

    $project = Project::factory()->published()->featured()->create([
        'title' => 'Featured Project One',
        'summary' => 'A featured project.',
        'repo_url' => 'https://git.example.test/featured-project-one',
        'live_url' => 'https://featured-project-one.example.test',
        'started_on' => '2023-01-01',
        'ended_on' => null,
        'is_placeholder' => true,
    ]);

    Award::factory()->published()->create([
        'title' => 'Fixture Award',
        'awarding_body' => 'Fixture Body',
        'awarded_at' => '2024-02-01',
        'is_placeholder' => true,
    ]);

    Writing::factory()->published()->create([
        'title' => 'Fixture Writing',
        'excerpt' => 'A fixture excerpt.',
        'body' => "## A heading\n\nHello world.",
        'published_at' => '2024-06-01 09:00:00',
        'is_placeholder' => true,
    ]);

    $tag = Tag::factory()->create(['name' => 'Laravel', 'is_placeholder' => true]);

    $project->tags()->attach($tag);
}

dataset('public page routes', [
    'home' => ['/', 'public/Home', function (Assert $page): void {
        $page->where('profile.name', 'Ada Fixture')
            ->where('profile.role_headline', 'Software Engineer')
            ->where('atAGlance.0.label', 'Experience')
            ->where('atAGlance.1.label', 'Current')
            ->where('atAGlance.1.value', 'Current Role @ Current Employer')
            ->where('atAGlance.2.label', 'Location')
            ->where('atAGlance.3.label', 'Availability')
            ->has('featuredProjects', 1)
            ->where('featuredProjects.0.title', 'Featured Project One')
            ->has('featuredProject')
            ->has('recentExperience', 1)
            ->where('recentExperience.0.title', 'Current Role')
            ->where('recentExperience.0.endDate', null)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true)
            // §3.5: the five below-the-fold sections are deferred, so they are
            // absent from the initial document and asserted in their own test.
            ->missing('recentWritings')
            ->missing('skillTags')
            ->missing('education')
            ->missing('certifications')
            ->missing('publications')
            ->missing('awards');
    }],

    'experience.index' => ['/experience', 'public/Experience', function (Assert $page): void {
        $page->has('experiences.data', 1)
            ->where('experiences.data.0.title', 'Current Role')
            ->where('experiences.data.0.employer', 'Current Employer')
            ->where('experiences.data.0.endDate', null)
            ->where('experiences.data.0.startDate', '2016-01-01')
            ->where('experiences.data.0.isPlaceholder', true)
            ->where('experiences.current_page', 1)
            ->where('experiences.per_page', 12)
            ->where('experiences.total', 1)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'education.index' => ['/education', 'public/Education', function (Assert $page): void {
        $page->has('education', 1)
            ->where('education.0.title', 'BSc Computing')
            ->where('education.0.institution', 'Fixture University')
            ->where('education.0.type', 'education')
            ->where('education.0.isPlaceholder', true)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'certifications.index' => ['/certifications', 'public/Certifications', function (Assert $page): void {
        $page->has('certifications', 1)
            ->where('certifications.0.title', 'Fixture Certification')
            ->where('certifications.0.issuer', 'Fixture Issuer')
            ->where('certifications.0.type', 'certification')
            ->where('certifications.0.isPlaceholder', true)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'publications.index' => ['/publications', 'public/Publications', function (Assert $page): void {
        $page->has('publications.data', 1)
            ->where('publications.data.0.title', 'Fixture Publication')
            ->where('publications.data.0.venue', 'Fixture Venue')
            ->where('publications.data.0.doiUrl', 'https://doi.org/10.1234/fixture.2024.1')
            ->where('publications.current_page', 1)
            ->where('publications.per_page', 12)
            ->where('publications.total', 1)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'projects.index' => ['/projects', 'public/Projects', function (Assert $page): void {
        $page->has('projects.data', 1)
            ->where('projects.data.0.title', 'Featured Project One')
            ->where('projects.data.0.isFeatured', true)
            ->where('projects.data.0.techStack', ['Laravel'])
            ->where('projects.current_page', 1)
            ->where('projects.per_page', 12)
            ->where('projects.total', 1)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'awards.index' => ['/awards', 'public/Awards', function (Assert $page): void {
        $page->has('awards', 1)
            ->where('awards.0.title', 'Fixture Award')
            ->where('awards.0.awardingBody', 'Fixture Body')
            ->where('awards.0.type', 'award')
            ->where('awards.0.isPlaceholder', true)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'writing.index' => ['/writing', 'public/Writing', function (Assert $page): void {
        $page->has('writings.data', 1)
            ->where('writings.data.0.title', 'Fixture Writing')
            ->where('writings.data.0.slug', 'fixture-writing')
            ->where('writings.data.0.href', '/writing/fixture-writing')
            ->where('writings.data.0.type', 'writing')
            ->where('writings.current_page', 1)
            ->where('writings.per_page', 10)
            ->where('writings.total', 1)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'cv' => ['/cv', 'public/Cv', function (Assert $page): void {
        $page->where('profile.name', 'Ada Fixture')
            ->where('summary', 'I build and maintain web applications.')
            ->has('skills')
            ->has('sections', 6)
            ->where('sections.0.key', 'experience')
            ->where('sections.0.items.0.title', 'Current Role')
            ->where('sections.1.key', 'education')
            ->where('sections.1.items.0.title', 'BSc Computing')
            ->where('sections.2.key', 'certifications')
            ->where('sections.3.key', 'publications')
            ->where('sections.4.key', 'projects')
            ->where('sections.5.key', 'awards')
            // Pinned decision 15: the download link is emitted only for a user
            // who owns a processed CV, so an anonymous visitor reads null.
            ->where('downloadUrl', null)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            // §3.6: `/cv` is `noindex` by default.
            ->where('noindex', true);
    }],

    'tags.index' => ['/tags', 'public/Tags', function (Assert $page): void {
        $page->has('tags', 1)
            ->where('tags.0.name', 'Laravel')
            ->where('tags.0.slug', 'laravel')
            ->where('tags.0.count', 1)
            ->where('tags.0.isPlaceholder', true)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'search' => ['/search', 'public/Search', function (Assert $page): void {
        $page->where('q', '')
            ->where('types', [])
            ->where('results', null)
            ->where('links', [])
            ->where('meta', null)
            ->where('message', null)
            ->has('title')
            ->has('description')
            ->has('canonical')
            ->has('jsonLd')
            // §3.8: search result pages are always `noindex`.
            ->where('noindex', true);
    }],

    'contact.show' => ['/contact', 'public/Contact', function (Assert $page): void {
        $page->where('title', 'Contact - '.config('app.name'))
            ->where('canonical', rtrim((string) config('app.url'), '/').'/contact')
            ->has('description')
            ->has('jsonLd')
            ->where('noindex', true);
    }],

    'privacy' => ['/privacy', 'public/Privacy', function (Assert $page): void {
        $page->where('title', 'Privacy - '.config('app.name'))
            ->where('description', 'How this site handles your data.')
            ->where('canonical', rtrim((string) config('app.url'), '/').'/privacy')
            ->has('policyVersion')
            ->has('lastUpdated')
            ->has('versionHistory', 1)
            ->has('jsonLd')
            ->where('noindex', true);
    }],
]);

test('every §3.2 page route answers 200 with its component and the props it renders from', function (string $uri, string $component, Closure $props) {
    seedPublicPageFixtures();

    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            // The environment half of the placeholder-badge rule: a badge shows
            // when the row's `is_placeholder` is true *and* the app is not in
            // production. It is a shared prop, so it is on every page.
            ->where('placeholderBadgeEnabled', true)
            ->tap($props)
        );
})->with('public page routes');

test('a paginated public list is a real URL that keeps page two out of the index', function () {
    seedPublicPageFixtures();

    // Twelve more roles, so page one is full and page two holds exactly one.
    foreach (range(1, 12) as $index) {
        WorkExperience::factory()->published()->create([
            'title' => "Earlier Role {$index}",
            'employer' => 'Earlier Employer',
            'start_date' => '2017-01-01',
            'end_date' => '2018-01-01',
        ]);
    }

    $this->get('/experience?page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Experience')
            ->has('experiences.data', 1)
            ->where('experiences.current_page', 2)
            ->where('experiences.per_page', 12)
            ->where('experiences.total', 13)
            ->where('canonical', rtrim((string) config('app.url'), '/').'/experience?page=2')
            ->where('noindex', true)
        );
});

test('the writing detail route renders the published writing it is bound to', function () {
    seedPublicPageFixtures();

    $writing = Writing::factory()->published()->create([
        'title' => 'A Published Note',
        'excerpt' => 'The published excerpt.',
        'body' => "## Section\n\nA paragraph.",
        'published_at' => '2024-07-01 12:00:00',
        'is_placeholder' => true,
    ]);

    $tag = Tag::factory()->create(['name' => 'Queueing']);
    $writing->tags()->attach($tag);

    $this->get('/writing/'.$writing->slug)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/WritingShow')
            ->where('writing.title', 'A Published Note')
            ->where('writing.slug', 'a-published-note')
            ->where('writing.excerpt', 'The published excerpt.')
            ->where('writing.bodyHtml', fn ($html) => is_string($html) && str_contains($html, '<h2>Section</h2>'))
            ->where('writing.isPlaceholder', true)
            ->has('writing.tags', 1)
            ->where('writing.tags.0.name', 'Queueing')
            ->where('writing.tags.0.slug', 'queueing')
            ->where('title', 'A Published Note - '.config('app.name'))
            ->where('canonical', rtrim((string) config('app.url'), '/').'/writing/a-published-note')
            ->has('description')
            ->has('jsonLd')
            ->where('noindex', true)
        );
});

test('an unknown writing slug is a 404, never a 403', function () {
    seedPublicPageFixtures();

    $this->get('/writing/this-slug-does-not-exist')->assertNotFound();
});

test('a draft writing is a 404 through the detail route', function () {
    seedPublicPageFixtures();

    $draft = Writing::factory()->draft()->create(['title' => 'Unreleased Note']);

    expect($draft->slug)->toBe('unreleased-note');

    $this->get('/writing/'.$draft->slug)->assertNotFound();
});

test('the writing index never lists a draft', function () {
    seedPublicPageFixtures();

    Writing::factory()->draft()->create(['title' => 'Unreleased Note']);

    $this->get('/writing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Writing')
            ->has('writings.data', 1)
            ->where('writings.data.0.title', 'Fixture Writing')
            ->where('writings.total', 1)
        );
});

test('the tag route renders the tag and the published items carrying it', function () {
    seedPublicPageFixtures();

    $this->get('/tags/laravel')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Tag')
            ->where('tag.name', 'Laravel')
            ->where('tag.slug', 'laravel')
            ->where('tag.isPlaceholder', true)
            ->has('items.data', 1)
            ->where('items.data.0.title', 'Featured Project One')
            ->where('items.data.0.type', 'project')
            ->where('items.current_page', 1)
            ->where('items.per_page', 24)
            ->where('items.total', 1)
            ->where('title', '#laravel - '.config('app.name'))
            ->where('canonical', rtrim((string) config('app.url'), '/').'/tags/laravel')
            ->has('description')
            ->has('jsonLd')
            ->where('noindex', true)
        );
});

test('an unknown tag slug is a 404', function () {
    seedPublicPageFixtures();

    $this->get('/tags/this-tag-does-not-exist')->assertNotFound();
});

test('a tag page never leaks a draft carrying the same tag', function () {
    seedPublicPageFixtures();

    $tag = Tag::query()->where('slug', 'laravel')->firstOrFail();

    $draft = Project::factory()->draft()->create(['title' => 'Unreleased Project']);
    $draft->tags()->attach($tag);

    $this->get('/tags/laravel')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Tag')
            ->has('items.data', 1)
            ->where('items.data.0.title', 'Featured Project One')
            ->where('items.total', 1)
        );
});

test('the home page loads its five deferred sections on request', function () {
    seedPublicPageFixtures();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Home')
            ->loadDeferredProps(fn (Assert $deferred) => $deferred
                ->has('recentWritings', 1)
                ->where('recentWritings.0.title', 'Fixture Writing')
                ->has('skillTags', 1)
                ->where('skillTags.0.slug', 'laravel')
                ->where('skillTags.0.count', 1)
                ->has('education', 1)
                ->where('education.0.title', 'BSc Computing')
                ->has('certifications', 1)
                ->where('certifications.0.title', 'Fixture Certification')
                ->has('publications', 1)
                ->where('publications.0.title', 'Fixture Publication')
                ->has('awards', 1)
                ->where('awards.0.title', 'Fixture Award')
            )
        );
});
