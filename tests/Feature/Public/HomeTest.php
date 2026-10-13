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
use Inertia\Testing\AssertableInertia;

test('the home page renders the hero, the at-a-glance strip and the eager sections', function () {
    Profile::factory()->create([
        'name' => 'Ada Placeholder',
        'role_headline' => 'Principal Platform Engineer',
        'positioning_sentence' => 'I build small, boring platforms that stay cheap to change.',
        'location' => 'Bristol, UK',
        'availability' => 'Open to contract work',
        'email' => 'owner@example.com',
        'years_experience_start' => now()->subYears(9)->toDateString(),
    ]);

    WorkExperience::factory()->published()->create([
        'title' => 'Principal Platform Engineer',
        'employer' => 'Example Labs',
        'end_date' => null,
    ]);

    Project::factory()->published()->create([
        'title' => 'Beacon Ledger',
        'is_featured' => true,
        'started_on' => '2024-01-01',
    ]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->where('profile.name', 'Ada Placeholder')
        ->where('profile.role_headline', 'Principal Platform Engineer')
        ->where('profile.positioning_sentence', 'I build small, boring platforms that stay cheap to change.')
        ->where('profile.location', 'Bristol, UK')
        ->where('profile.availability', 'Open to contract work')
        ->where('atAGlance', [
            ['label' => 'Experience', 'value' => '9 years'],
            ['label' => 'Current', 'value' => 'Principal Platform Engineer @ Example Labs'],
            ['label' => 'Location', 'value' => 'Bristol, UK'],
            ['label' => 'Availability', 'value' => 'Open to contract work'],
        ])
        ->where('title', config('app.name').' — Principal Platform Engineer')
        ->where('description', 'I build small, boring platforms that stay cheap to change.')
        ->has('featuredProjects', 1)
        ->where('featuredProjects.0.title', 'Beacon Ledger')
        ->where('featuredProject.title', 'Beacon Ledger')
        ->has('recentExperience', 1)
        ->where('recentExperience.0.title', 'Principal Platform Engineer')
        ->where('recentExperience.0.employer', 'Example Labs')
        ->where('recentExperience.0.endDate', null)
        ->where('placeholderBadgeEnabled', true)
        ->etc()
    );
});

test('the hero CTAs and the contact affordances point at the public surfaces they name', function () {
    Profile::factory()->create([
        'name' => 'Ada Placeholder',
        'role_headline' => 'Principal Platform Engineer',
        'email' => 'owner@example.com',
        'github_url' => 'https://github.example.com/owner',
        'linkedin_url' => 'https://linkedin.example.com/in/owner',
        'orcid_url' => 'https://orcid.example.org/0000-0002-1825-0097',
    ]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->where('profile.email', 'owner@example.com')
        ->where('profile.github_url', 'https://github.example.com/owner')
        ->where('profile.linkedin_url', 'https://linkedin.example.com/in/owner')
        ->where('profile.orcid_url', 'https://orcid.example.org/0000-0002-1825-0097')
        ->etc()
    );

    // §3.5 item 3: the three CTAs are "View CV", "Print / Save as PDF" and
    // "Contact". The print CTA is `window.print()` and has no server surface;
    // the other two are the named routes the hero links to.
    expect(route('cv', absolute: false))->toBe('/cv');
    expect(route('contact.show', absolute: false))->toBe('/contact');
});

test('the deferred home sections are absent from the first response and load on the deferred request', function () {
    Profile::factory()->create();

    $writing = Writing::factory()->published()->create(['title' => 'A Deferred Note']);
    $tag = Tag::factory()->create(['name' => 'Deferred Skill']);
    $writing->tags()->attach($tag);

    // A second, busier tag so the cloud's count-descending order is assertable.
    $busier = Tag::factory()->create(['name' => 'Busier Skill']);
    $writing->tags()->attach($busier);
    Project::factory()->published()->create(['title' => 'A Tagged Project'])->tags()->attach($busier);

    // A draft carrying the same tag must not inflate the count: §3.7 counts
    // published items only, and §3.5's cloud has to agree with `/tags`.
    Writing::factory()->draft()->create(['title' => 'A Draft Note'])->tags()->attach($busier);

    Education::factory()->published()->create(['title' => 'BSc Deferred']);
    Certification::factory()->published()->create(['title' => 'Deferred Certificate']);
    Publication::factory()->published()->create(['title' => 'Deferred Paper']);
    Award::factory()->published()->create(['title' => 'Deferred Award']);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->missing('recentWritings')
        ->missing('skillTags')
        ->missing('education')
        ->missing('certifications')
        ->missing('publications')
        ->missing('awards')
        ->etc()
    );

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps(fn (AssertableInertia $reloaded) => $reloaded
            ->has('recentWritings', 1)
            ->where('recentWritings.0.title', 'A Deferred Note')
            ->where('recentWritings.0.href', route('writing.show', $writing))
            ->has('skillTags', 2)
            // §3.5 section 4: the cloud is ordered by usage count, descending,
            // and the count is published-only, matching `/tags` (§3.7). The
            // draft attached to `$busier` above is what makes the count 2 rather
            // than the 3 an all-attachments count would report.
            ->where('skillTags.0.name', 'Busier Skill')
            ->where('skillTags.0.count', 2)
            ->where('skillTags.1.name', 'Deferred Skill')
            ->where('skillTags.1.slug', $tag->slug)
            ->where('skillTags.1.count', 1)
            ->has('education', 1)
            ->where('education.0.title', 'BSc Deferred')
            ->has('certifications', 1)
            ->where('certifications.0.title', 'Deferred Certificate')
            ->has('publications', 1)
            ->where('publications.0.title', 'Deferred Paper')
            ->has('awards', 1)
            ->where('awards.0.title', 'Deferred Award')
            ->etc()
        )
        ->etc()
    );
});

test('the newest published featured project drives the pinned note', function () {
    Profile::factory()->create();

    Project::factory()->published()->create([
        'title' => 'Older Featured',
        'is_featured' => true,
        'started_on' => '2023-01-01',
    ]);

    $newer = Project::factory()->published()->create([
        'title' => 'Newer Featured',
        'is_featured' => true,
        'started_on' => '2025-01-01',
    ]);

    Project::factory()->published()->create([
        'title' => 'Unfeatured And Newest',
        'is_featured' => false,
        'started_on' => '2026-01-01',
    ]);

    Project::factory()->draft()->create([
        'title' => 'Draft And Featured',
        'is_featured' => true,
        'started_on' => '2027-01-01',
    ]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->has('featuredProjects', 2)
        ->where('featuredProjects.0.title', 'Newer Featured')
        ->where('featuredProjects.1.title', 'Older Featured')
        ->where('featuredProject.id', $newer->getKey())
        ->where('featuredProject.title', 'Newer Featured')
        ->where('featuredProject.href', $newer->live_url)
        ->etc()
    );
});

test('the home page caps the featured projects, the recent roles and the writing highlights', function () {
    Profile::factory()->create();

    foreach (range(1, 8) as $index) {
        Project::factory()->published()->create([
            'title' => 'Featured Project '.$index,
            'is_featured' => true,
            'started_on' => now()->subYears($index)->toDateString(),
        ]);
    }

    $endDates = ['2025-01-01', '2024-01-01', '2023-01-01', '2022-01-01', '2021-01-01'];

    foreach ($endDates as $index => $endDate) {
        WorkExperience::factory()->published()->create([
            'title' => 'Role '.$index,
            'end_date' => $endDate,
        ]);
    }

    Writing::factory()->published()->count(5)->create();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->has('featuredProjects', 6)
        ->has('recentExperience', 3)
        // The three most recent roles, newest end date first.
        ->where('recentExperience.0.title', 'Role 0')
        ->where('recentExperience.1.title', 'Role 1')
        ->where('recentExperience.2.title', 'Role 2')
        ->etc()
    );

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps(fn (AssertableInertia $reloaded) => $reloaded
            ->has('recentWritings', 3)
            ->etc()
        )
        ->etc()
    );
});

test('the pinned note has no subject when nothing is featured', function () {
    Profile::factory()->create();

    Project::factory()->published()->create([
        'title' => 'Unfeatured',
        'is_featured' => false,
    ]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->has('featuredProjects', 0)
        ->where('featuredProject', null)
        ->etc()
    );
});

test('the at-a-glance strip reports only the facts the profile provides', function () {
    Profile::factory()->create([
        'location' => null,
        'availability' => null,
        'years_experience_start' => null,
    ]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->where('atAGlance', [])
        ->etc()
    );
});

test('the home page is noindex until the site is marked indexable', function () {
    Profile::factory()->create();

    config(['app.indexable' => false]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->where('noindex', true)
        ->etc()
    );

    config(['app.indexable' => true]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->where('noindex', false)
        ->etc()
    );
});

test('a tag attached only to drafts counts zero in the home cloud', function () {
    Profile::factory()->create();

    $published = Tag::factory()->create(['name' => 'Published Skill']);
    $draftOnly = Tag::factory()->create(['name' => 'Draft Only Skill']);

    Writing::factory()->published()->create(['title' => 'A Published Note'])->tags()->attach($published);
    Writing::factory()->draft()->create(['title' => 'A Draft Note'])->tags()->attach($draftOnly);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
            ->has('skillTags', 2)
            ->where('skillTags.0.slug', 'published-skill')
            ->where('skillTags.0.count', 1)
            ->where('skillTags.1.slug', 'draft-only-skill')
            ->where('skillTags.1.count', 0)
        )
        ->etc()
    );
});

/*
 * `Home.vue`'s `CardItem` types `href` as `href?: string | null` because an
 * award has no destination: the server sends `href: null` here, and
 * `ContentCard.vue` renders a plain surface for it. The TypeScript annotation
 * itself is guarded by `npm run types:check`; this test pins the server contract
 * that forces it to be nullable.
 */
test('an award card hands the home page a null href', function () {
    Profile::factory()->create();

    Award::factory()->published()->create(['title' => 'An Award Without A Link']);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Home')
        ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
            ->where('awards.0.title', 'An Award Without A Link')
            ->where('awards.0.href', null)
        )
        ->etc()
    );
});
