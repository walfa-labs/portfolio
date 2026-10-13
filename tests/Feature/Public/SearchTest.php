<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\Writing;
use Inertia\Testing\AssertableInertia;

test('a matching query renders the ranked results', function () {
    $tag = Tag::factory()->create(['name' => 'Zzneedle']);

    $project = Project::factory()->published()->create(['title' => 'A Plain Project']);
    $project->tags()->attach($tag);

    $writing = Writing::factory()->published()->create(['title' => 'Zzneedle in the title']);

    $this->get('/search?q=zzneedle')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('q', 'zzneedle')
        ->where('types', [])
        ->has('results', 2)
        // §3.8's ranking: an exact tag hit outranks a title hit.
        ->where('results.0.id', $project->getKey())
        ->where('results.0.type', 'project')
        ->where('results.0.title', 'A Plain Project')
        ->where('results.0.href', route('projects.index', absolute: false))
        ->where('results.0.score', fn ($score): bool => is_numeric($score) && abs((float) $score - 1.0) < 0.001)
        ->where('results.1.id', $writing->getKey())
        ->where('results.1.type', 'writing')
        ->where('results.1.title', 'Zzneedle in the title')
        ->where('results.1.href', route('writing.show', $writing, false))
        ->where('results.1.score', fn ($score): bool => is_numeric($score) && abs((float) $score - 0.75) < 0.001)
        ->where('message', null)
        ->where('meta', [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 20,
            'total' => 2,
        ])
        ->etc()
    );
});

test('a draft is never a search result', function () {
    Writing::factory()->published()->create(['title' => 'Zzneedle published']);
    Writing::factory()->draft()->create(['title' => 'Zzneedle draft']);

    $this->get('/search?q=zzneedle')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->has('results', 1)
        ->where('results.0.title', 'Zzneedle published')
        ->where('meta.total', 1)
        ->etc()
    );
});

test('a query shorter than two characters is answered with a message, not rejected', function () {
    Writing::factory()->published()->create(['title' => 'Anything At All']);

    $this->get('/search?q=a')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('q', 'a')
        ->where('results', null)
        ->where('meta', null)
        ->where('links', [])
        ->where('message', 'Enter at least 2 characters to search.')
        ->etc()
    );
});

test('an absent or empty query renders the empty search page without a message', function () {
    $this->get('/search')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('q', '')
        ->where('results', null)
        ->where('meta', null)
        ->where('message', null)
        ->etc()
    );

    $this->get('/search?q=')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('q', '')
        ->where('results', null)
        ->where('message', null)
        ->etc()
    );
});

test('the singular type filter narrows the results', function () {
    Writing::factory()->published()->create(['title' => 'Zzneedle writing']);
    Project::factory()->published()->create(['title' => 'Zzneedle project']);

    $this->get('/search?q=zzneedle')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('types', [])
        ->has('results', 2)
        ->where('meta.total', 2)
        ->etc()
    );

    $this->get('/search?q=zzneedle&type=writing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('types', ['writing'])
        ->has('results', 1)
        ->where('results.0.type', 'writing')
        ->where('meta.total', 1)
        ->etc()
    );

    $this->get('/search?q=zzneedle&type=project')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('types', ['project'])
        ->has('results', 1)
        ->where('results.0.type', 'project')
        ->where('meta.total', 1)
        ->etc()
    );
});

test('the search page is noindex by header and by prop, indexable or not', function () {
    config(['app.indexable' => false]);

    $default = $this->get('/search?q=zzneedle');

    $default->assertOk();
    $default->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $default->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('noindex', true)
        ->etc()
    );

    config(['app.indexable' => true]);

    $indexable = $this->get('/search?q=zzneedle');

    $indexable->assertOk();
    $indexable->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $indexable->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->where('noindex', true)
        ->etc()
    );
});

test('the search page returns twenty results per page', function () {
    for ($index = 1; $index <= 25; $index++) {
        Writing::factory()->published()->create([
            'title' => sprintf('Zzneedle note %02d', $index),
        ]);
    }

    $first = $this->get('/search?q=zzneedle');

    $first->assertOk();
    $first->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->has('results', 20)
        ->where('meta', [
            'current_page' => 1,
            'last_page' => 2,
            'per_page' => 20,
            'total' => 25,
        ])
        ->has('links')
        ->etc()
    );

    $second = $this->get('/search?q=zzneedle&page=2');

    $second->assertOk();
    $second->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Search')
        ->has('results', 5)
        ->where('meta', [
            'current_page' => 2,
            'last_page' => 2,
            'per_page' => 20,
            'total' => 25,
        ])
        ->etc()
    );

    // The paginator keeps the query string, so page 2 is still a search URL.
    $links = $second->viewData('page')['props']['links'];
    $urls = array_column($links, 'url');

    expect($urls)->toContain(route('search', ['q' => 'zzneedle', 'page' => 1]));
});
