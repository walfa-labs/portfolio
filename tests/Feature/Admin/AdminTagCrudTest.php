<?php

use App\Models\Tag;
use App\Models\User;
use App\Models\WorkExperience;
use App\Support\ContentSlug;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Tag CRUD and attach/detach through the admin UI (§5.9, §5.10, §11.4)
|--------------------------------------------------------------------------
|
| `tags` is the one admin resource that is not a content type (§2.5): it has
| `name` rather than `title`, no `status`/`published_at` pair, no `sort_order`,
| and no pivot of its own. It is also the one resource whose destroy is a hard
| delete — `Tag` does not use `SoftDeletes`, so the pivot rows go through the
| `taggables` foreign key's `ON DELETE CASCADE` rather than a `deleted_at`.
|
| Attaching and detaching happens inside each content form's `TagPicker`,
| which sends `tag_ids: int[]` — the flat admin contract, deliberately not the
| API's JSON:API relationship object (§5.9). A payload that omits `tag_ids`
| leaves the associations alone; an empty list clears them.
|
*/

test('store creates the tag and redirects with a toast', function () {
    $this->actingAs(User::factory()->administrator()->create());

    $response = $this->post('/admin/tags', ['name' => 'Admin Tag']);

    $response->assertRedirect('/admin/tags');
    $response->assertSessionHas('inertia.flash_data.toast', fn (mixed $toast): bool => is_array($toast)
        && ($toast['type'] ?? null) === 'success'
        && filled($toast['message'] ?? null));

    $tag = Tag::query()->where('name', 'Admin Tag')->firstOrFail();

    // §5.9: the slug is derived from the name on create and is never a field.
    expect($tag->slug)->toBe(ContentSlug::canonicalize('Admin Tag'));
});

test('edit returns 200 with the record prop', function () {
    $this->actingAs(User::factory()->administrator()->create());

    $tag = Tag::factory()->create(['name' => 'Edit Tag']);

    $response = $this->get('/admin/tags/'.$tag->getKey().'/edit');

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('admin/tags/Edit')
        ->where('record.id', $tag->getKey())
        ->where('record.name', 'Edit Tag')
        ->where('record.slug', $tag->slug));
});

test('update changes the tag', function () {
    $this->actingAs(User::factory()->administrator()->create());

    $tag = Tag::factory()->create(['name' => 'Original Tag Name']);

    $response = $this->patch('/admin/tags/'.$tag->getKey(), ['name' => 'Renamed Tag']);

    $response->assertRedirect('/admin/tags');
    $this->assertDatabaseHas('tags', ['id' => $tag->getKey(), 'name' => 'Renamed Tag']);
});

test('destroy hard-deletes the tag', function () {
    $this->actingAs(User::factory()->administrator()->create());

    $tag = Tag::factory()->create(['name' => 'Doomed Tag']);

    $response = $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/admin/tags/'.$tag->getKey());

    $response->assertRedirect('/admin/tags');
    $this->assertDatabaseMissing('tags', ['id' => $tag->getKey()]);
});

test('attaching and detaching a tag through the tag_ids payload', function () {
    $this->actingAs(User::factory()->administrator()->create());

    $workExperience = WorkExperience::factory()->create([
        'title' => 'Tag Pivot Work Experience',
        'employer' => 'Tag Pivot Employer',
        'start_date' => '2021-01-01',
    ]);
    $tag = Tag::factory()->create(['name' => 'Pivot Tag']);

    /*
     * Attach: the admin form sends `tag_ids: int[]`, and the controller syncs
     * the pivot. `taggable_type` is the morph-map key, not the class name.
     */
    $this->patch('/admin/work-experiences/'.$workExperience->getKey(), [
        'title' => 'Tag Pivot Work Experience',
        'tag_ids' => [$tag->getKey()],
    ])->assertRedirect('/admin/work-experiences');

    $this->assertDatabaseHas('taggables', [
        'tag_id' => $tag->getKey(),
        'taggable_type' => 'work_experience',
        'taggable_id' => $workExperience->getKey(),
    ]);

    /*
     * Detach: an empty `tag_ids` list clears the association. The tag row
     * itself survives — detaching is not deleting.
     */
    $this->patch('/admin/work-experiences/'.$workExperience->getKey(), [
        'title' => 'Tag Pivot Work Experience',
        'tag_ids' => [],
    ])->assertRedirect('/admin/work-experiences');

    $this->assertDatabaseMissing('taggables', [
        'tag_id' => $tag->getKey(),
        'taggable_type' => 'work_experience',
        'taggable_id' => $workExperience->getKey(),
    ]);
    $this->assertDatabaseHas('tags', ['id' => $tag->getKey()]);
});

test('a validation failure redirects back with the exact error key', function () {
    $this->actingAs(User::factory()->administrator()->create());

    // `name` is the only required field on a tag, so an empty payload fails on
    // exactly that key (§5.10).
    $response = $this->from('/admin/tags/create')->post('/admin/tags', []);

    $response->assertStatus(302);
    $response->assertSessionHasErrors(['name']);

    expect(Tag::query()->count())->toBe(0);
});
