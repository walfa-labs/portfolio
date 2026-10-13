<?php

use App\Models\Award;
use App\Models\Project;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;
use Inertia\Testing\AssertableInertia;

test('the tag index lists every tag with its published count and ignores drafts', function () {
    $busy = Tag::factory()->create(['name' => 'Busy Topic']);
    $shared = Tag::factory()->create(['name' => 'Shared Topic']);
    $unused = Tag::factory()->create(['name' => 'Unused Topic']);

    // One published project and one published writing: the count is across types.
    Project::factory()->published()->create(['title' => 'Published Project'])->tags()->attach($busy);
    Writing::factory()->published()->create(['title' => 'Published Note'])->tags()->attach($busy);

    // One published and one draft writing on the same tag: only the published
    // one is counted (§3.1 item 4).
    Writing::factory()->published()->create(['title' => 'Second Published Note'])->tags()->attach($shared);
    Writing::factory()->draft()->create(['title' => 'Draft Note'])->tags()->attach($shared);

    $this->get('/tags')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Tags')
        ->has('tags', 3)
        ->where('tags', [
            ['name' => 'Busy Topic', 'slug' => 'busy-topic', 'count' => 2, 'isPlaceholder' => true],
            ['name' => 'Shared Topic', 'slug' => 'shared-topic', 'count' => 1, 'isPlaceholder' => true],
            ['name' => 'Unused Topic', 'slug' => 'unused-topic', 'count' => 0, 'isPlaceholder' => true],
        ])
        ->where('title', 'Tags - '.config('app.name'))
        ->where('canonical', route('tags.index'))
        ->etc()
    );
});

test('a tag page groups its items by content type and hides drafts', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel']);
    $other = Tag::factory()->create(['name' => 'Vue']);

    $role = WorkExperience::factory()->published()->create([
        'title' => 'Platform Engineer',
        'end_date' => null,
    ]);
    $project = Project::factory()->published()->create(['title' => 'Beacon Ledger']);
    $award = Award::factory()->published()->create(['title' => 'Excellence Award']);
    $writing = Writing::factory()->published()->create(['title' => 'Reading A Query Plan']);

    $draft = Writing::factory()->draft()->create(['title' => 'Draft On The Tag']);
    $elsewhere = Writing::factory()->published()->create(['title' => 'Tagged Something Else']);

    $role->tags()->attach($tag);
    $project->tags()->attach($tag);
    $award->tags()->attach($tag);
    $writing->tags()->attach($tag);
    $draft->tags()->attach($tag);
    $elsewhere->tags()->attach($other);

    $response = $this->get('/tags/laravel');

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Tag')
        ->where('tag.name', 'Laravel')
        ->where('tag.slug', 'laravel')
        ->where('items.total', 4)
        ->where('items.per_page', 24)
        ->has('items.data', 4)
        // §3.7's fixed group order: experience → education → certifications →
        // publications → projects → awards → writing.
        ->where('items.data.0.type', 'work_experience')
        ->where('items.data.0.title', 'Platform Engineer')
        ->where('items.data.1.type', 'project')
        ->where('items.data.1.title', 'Beacon Ledger')
        ->where('items.data.2.type', 'award')
        ->where('items.data.2.title', 'Excellence Award')
        ->where('items.data.3.type', 'writing')
        ->where('items.data.3.title', 'Reading A Query Plan')
        ->where('items.data.3.href', route('writing.show', $writing, false))
        ->where('title', '#laravel - '.config('app.name'))
        ->where('canonical', route('tags.show', $tag))
        ->etc()
    );

    $titles = array_column($response->viewData('page')['props']['items']['data'], 'title');

    expect($titles)->not->toContain('Draft On The Tag');
    expect($titles)->not->toContain('Tagged Something Else');
});

test('a tag whose only items are drafts renders an empty page', function () {
    $tag = Tag::factory()->create(['name' => 'Drafts Only']);
    Writing::factory()->draft()->create(['title' => 'Draft Only Note'])->tags()->attach($tag);

    $this->get('/tags/drafts-only')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Tag')
        ->has('items.data', 0)
        ->where('items.total', 0)
        ->etc()
    );
});

test('a tag page paginates twenty-four items across every group, not twenty-four per group', function () {
    $tag = Tag::factory()->create(['name' => 'Heavy Tag']);

    $writings = Writing::factory()->published()->count(20)->create();
    $projects = Project::factory()->published()->count(6)->create();

    foreach ($writings as $writing) {
        $writing->tags()->attach($tag);
    }

    foreach ($projects as $project) {
        $project->tags()->attach($tag);
    }

    $first = $this->get('/tags/heavy-tag');

    $first->assertOk();
    $first->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Tag')
        ->has('items.data', 24)
        ->where('items.total', 26)
        ->where('items.per_page', 24)
        ->where('items.current_page', 1)
        ->where('items.last_page', 2)
        ->etc()
    );

    $types = array_column($first->viewData('page')['props']['items']['data'], 'type');

    expect(array_values(array_unique($types)))->toBe(['project', 'writing']);

    $second = $this->get('/tags/heavy-tag?page=2');

    $second->assertOk();
    $second->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Tag')
        ->has('items.data', 2)
        ->where('items.current_page', 2)
        ->where('items.total', 26)
        ->etc()
    );
});

test('an unknown tag slug is a 404', function () {
    $this->get('/tags/no-such-tag')->assertNotFound();
});

test('a tag page addresses the tag by slug rather than by id', function () {
    $tag = Tag::factory()->create(['name' => 'Slug Addressed']);

    expect($tag->getRouteKeyName())->toBe('slug');

    $this->get('/tags/'.$tag->getKey())->assertNotFound();
});
