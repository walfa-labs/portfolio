<?php

use App\Enums\ContentStatus;
use App\Models\Tag;
use App\Models\Writing;
use App\Models\WritingSeries;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Writing detail (§3.9)
|--------------------------------------------------------------------------
|
| The response body is the client-side shell during the suite (no SSR server
| listens on 127.0.0.1:13714), so the stylesheet that makes a code block
| scroll is asserted against the component source rather than rendered HTML.
|
*/

function writingShowComponentSource(): string
{
    return File::get(base_path('resources/js/pages/public/WritingShow.vue'));
}

test('a published writing renders its Markdown, its tags and its reading time', function () {
    $writing = Writing::factory()->published()->create([
        'title' => 'Reading A Query Plan',
        'excerpt' => 'A short excerpt about query plans.',
        'body' => "## A heading\n\nHello world.",
    ]);

    $tag = Tag::factory()->create(['name' => 'Databases']);
    $writing->tags()->attach($tag);

    $this->get('/writing/reading-a-query-plan')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/WritingShow')
        ->where('writing.title', 'Reading A Query Plan')
        ->where('writing.slug', 'reading-a-query-plan')
        ->where('writing.excerpt', 'A short excerpt about query plans.')
        ->where('writing.bodyHtml', fn ($html): bool => is_string($html)
            && str_contains($html, '<h2>A heading</h2>')
            && str_contains($html, '<p>Hello world.</p>'))
        ->where('writing.readingTime', 1)
        ->where('writing.publishedLabel', $writing->published_at->format('j F Y'))
        ->where('writing.tags', [['name' => 'Databases', 'slug' => 'databases']])
        ->where('title', 'Reading A Query Plan - '.config('app.name'))
        ->where('canonical', route('writing.show', $writing))
        ->etc()
    );
});

test('a fenced and an indented code block both render as focusable scroll containers', function () {
    Writing::factory()->published()->create([
        'title' => 'Reading A Query Plan',
        'body' => "```php\nroute('writing.show');\n```\n\n    indented code\n",
    ]);

    $this->get('/writing/reading-a-query-plan')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/WritingShow')
        ->where('writing.bodyHtml', function ($html): bool {
            if (! is_string($html) || preg_match_all('/<pre\b[^>]*>/', $html, $preTags) !== 2) {
                return false;
            }

            return collect($preTags[0])->every(fn (string $tag): bool => str_contains($tag, 'tabindex="0"'))
                && str_contains($html, '<code class="language-php">')
                && str_contains($html, 'indented code');
        })
        ->etc()
    );
});

test('the code block stylesheet scrolls wide code instead of wrapping it', function () {
    $source = writingShowComponentSource();

    expect($source)->toContain('overflow-x: auto')
        ->and($source)->not->toContain('white-space: pre-wrap')
        ->and($source)->not->toContain('overflow-wrap: anywhere');
});

test('the series label appears when the writing belongs to a series', function () {
    $series = WritingSeries::factory()->create(['title' => 'Small Systems, Carefully']);

    Writing::factory()->published()->create([
        'title' => 'Reading A Query Plan',
        'series_id' => $series->getKey(),
    ]);

    $this->get('/writing/reading-a-query-plan')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/WritingShow')
        ->where('writing.series', 'Small Systems, Carefully')
        ->where('writing.seriesSlug', 'small-systems-carefully')
        ->etc()
    );
});

test('a writing outside a series carries no series label', function () {
    Writing::factory()->published()->create(['title' => 'A Standalone Note']);

    $this->get('/writing/a-standalone-note')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/WritingShow')
        ->where('writing.series', null)
        ->where('writing.seriesSlug', null)
        ->etc()
    );
});

test('the reading time scales with the length of the body', function () {
    Writing::factory()->published()->create([
        'title' => 'A Long Note',
        'body' => implode(' ', array_fill(0, 600, 'word')),
    ]);

    $this->get('/writing/a-long-note')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/WritingShow')
        ->where('writing.readingTime', 3)
        ->etc()
    );
});

test('a writing with no body renders no HTML and no reading time', function () {
    Writing::factory()->published()->create([
        'title' => 'A Blank Note',
        'body' => null,
    ]);

    $this->get('/writing/a-blank-note')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/WritingShow')
        ->where('writing.bodyHtml', null)
        ->where('writing.readingTime', null)
        ->etc()
    );
});

test('a draft writing is a 404 even though its slug exists', function () {
    $draft = Writing::factory()->draft()->create(['title' => 'Unfinished Business']);

    expect(Writing::query()->where('slug', 'unfinished-business')->exists())->toBeTrue()
        ->and($draft->status)->toBe(ContentStatus::Draft);

    $this->get('/writing/unfinished-business')->assertNotFound();
});

test('a writing scheduled for the future is a 404', function () {
    Writing::factory()->create([
        'title' => 'Tomorrow Notes',
        'status' => ContentStatus::Published,
        'published_at' => now()->addDay(),
    ]);

    $this->get('/writing/tomorrow-notes')->assertNotFound();
});

test('an unknown writing slug is a 404', function () {
    $this->get('/writing/this-post-does-not-exist')->assertNotFound();
});
