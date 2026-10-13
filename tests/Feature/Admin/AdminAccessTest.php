<?php

use App\Models\Award;
use App\Models\Certification;
use App\Models\ContactMessage;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkExperience;
use App\Models\Writing;

/*
|--------------------------------------------------------------------------
| The three-way authorisation gate over every admin route (§5.7, §5.8, §11.3)
|--------------------------------------------------------------------------
|
| One dataset carries the eight §5.8 resources, and each test below walks the
| whole verb set for one leg of the three-way split: a guest is redirected to
| the login page, an authenticated non-administrator is answered `403`, and
| the configured administrator succeeds. The point of the dataset is that a
| verb gated correctly for `work-experiences` and left open for `tags` cannot
| pass unnoticed — every resource answers the same twelve verbs.
|
| The two gates are distinct by design (§5.7). The `admin` middleware
| (`EnsureUserIsAdministrator`) guards the whole `/admin` group and is what a
| non-administrator trips; the per-resource policies are the second,
| per-record check the controllers call through `$this->authorize(...)`. A
| denial at either layer is a `403`, never a `404` that hides the route.
|
| Writes are Inertia visits, not API calls: the body is flat form data, the
| destructive routes carry `password.confirm`, and a successful write is a
| `302` back to the index with a `toast` flash (§5.9).
|
*/

dataset('admin access resources', [
    'work-experiences' => [
        'work-experiences',
        WorkExperience::class,
        'work_experience',
        true,
        'title',
        ['title' => 'Access Work Experience', 'employer' => 'Access Employer', 'start_date' => '2021-01-01'],
    ],
    'educations' => [
        'educations',
        Education::class,
        'education',
        true,
        'title',
        ['title' => 'Access Education', 'institution' => 'Access Institution'],
    ],
    'certifications' => [
        'certifications',
        Certification::class,
        'certification',
        true,
        'title',
        ['title' => 'Access Certification', 'issuer' => 'Access Issuer', 'issued_on' => '2021-01-01'],
    ],
    'publications' => [
        'publications',
        Publication::class,
        'publication',
        true,
        'title',
        ['title' => 'Access Publication', 'venue' => 'Access Venue', 'issued_on' => '2021-01-01'],
    ],
    'projects' => [
        'projects',
        Project::class,
        'project',
        true,
        'title',
        ['title' => 'Access Project'],
    ],
    'awards' => [
        'awards',
        Award::class,
        'award',
        true,
        'title',
        ['title' => 'Access Award', 'awarding_body' => 'Access Awarding Body', 'awarded_at' => '2021-01-01'],
    ],
    'writings' => [
        'writings',
        Writing::class,
        'writing',
        false,
        'title',
        ['title' => 'Access Writing'],
    ],
    'tags' => [
        'tags',
        Tag::class,
        'tag',
        false,
        'name',
        ['name' => 'Access Tag'],
    ],
]);

/**
 * Every verb §5.8 routes for one resource, as `[method, uri, body]`.
 *
 * The bodies are the ones a successful administrator send would send — the
 * create payload, a partial update naming only the key column, and the
 * `ids: int[]` list the bulk verbs and `reorder` carry. For the guest and
 * non-administrator legs the body never reaches validation, so its exact
 * shape is irrelevant there; it is filled in so one list serves all three.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function adminAccessVerbs(string $segment, int $id, bool $orderable, string $titleKey, array $createAttributes): array
{
    $verbs = [
        ['GET', "/admin/{$segment}", []],
        ['GET', "/admin/{$segment}/create", []],
        ['GET', "/admin/{$segment}/{$id}/edit", []],
        ['POST', "/admin/{$segment}", $createAttributes],
        ['PATCH', "/admin/{$segment}/{$id}", [$titleKey => 'Updated '.$createAttributes[$titleKey]]],
        ['DELETE', "/admin/{$segment}/{$id}", []],
        ['PATCH', "/admin/{$segment}/{$id}/publish", []],
        ['PATCH', "/admin/{$segment}/{$id}/unpublish", []],
    ];

    if ($orderable) {
        $verbs[] = ['PATCH', "/admin/{$segment}/reorder", ['ids' => [$id]]];
    }

    $verbs[] = ['DELETE', "/admin/{$segment}", ['ids' => [$id]]];
    $verbs[] = ['PATCH', "/admin/{$segment}/publish", ['ids' => [$id]]];
    $verbs[] = ['PATCH', "/admin/{$segment}/unpublish", ['ids' => [$id]]];

    return $verbs;
}

test('guests are redirected to the login page from every admin resource route', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    // The record exists, so a redirect is the `auth` middleware's answer and
    // not a `404` for a missing row the guest never reached.
    $record = $modelClass::factory()->create($createAttributes);

    foreach (adminAccessVerbs($segment, $record->getKey(), $orderable, $titleKey, $createAttributes) as [$method, $uri, $body]) {
        $this->call($method, $uri, $body)->assertRedirect(route('login'));
    }
})->with('admin access resources');

test('an authenticated non-administrator is forbidden from every admin resource route', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->create());

    $record = $modelClass::factory()->create($createAttributes);

    foreach (adminAccessVerbs($segment, $record->getKey(), $orderable, $titleKey, $createAttributes) as [$method, $uri, $body]) {
        $this->call($method, $uri, $body)->assertForbidden();
    }
})->with('admin access resources');

test('the administrator succeeds on every admin resource route', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    /*
     * Writes answer `302` back to the index and are asserted first, because
     * they need no build; the reads answer `200` and render an Inertia page,
     * which needs the Vite manifest. Each verb that addresses one record gets
     * a fresh fixture, so a destructive verb cannot soft-delete (or
     * hard-delete, for `tags`) a row a later verb still needs.
     */
    $this->post("/admin/{$segment}", $createAttributes)->assertRedirect("/admin/{$segment}");

    $update = $modelClass::factory()->create($createAttributes);
    $this->patch("/admin/{$segment}/{$update->getKey()}", [$titleKey => 'Updated '.$createAttributes[$titleKey]])
        ->assertRedirect("/admin/{$segment}");

    $destroy = $modelClass::factory()->create($createAttributes);
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete("/admin/{$segment}/{$destroy->getKey()}")
        ->assertRedirect("/admin/{$segment}");

    $publish = $modelClass::factory()->create($createAttributes);
    $this->patch("/admin/{$segment}/{$publish->getKey()}/publish")->assertRedirect("/admin/{$segment}");

    $unpublish = $modelClass::factory()->create($createAttributes);
    $this->patch("/admin/{$segment}/{$unpublish->getKey()}/unpublish")->assertRedirect("/admin/{$segment}");

    if ($orderable) {
        $reorder = $modelClass::factory()->create($createAttributes);
        $this->patch("/admin/{$segment}/reorder", ['ids' => [$reorder->getKey()]])
            ->assertRedirect("/admin/{$segment}");
    }

    $bulkDestroy = $modelClass::factory()->create($createAttributes);
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete("/admin/{$segment}", ['ids' => [$bulkDestroy->getKey()]])
        ->assertRedirect("/admin/{$segment}");

    $bulkPublish = $modelClass::factory()->create($createAttributes);
    $this->patch("/admin/{$segment}/publish", ['ids' => [$bulkPublish->getKey()]])
        ->assertRedirect("/admin/{$segment}");

    $bulkUnpublish = $modelClass::factory()->create($createAttributes);
    $this->patch("/admin/{$segment}/unpublish", ['ids' => [$bulkUnpublish->getKey()]])
        ->assertRedirect("/admin/{$segment}");

    // Reads.
    $this->get("/admin/{$segment}")->assertOk();
    $this->get("/admin/{$segment}/create")->assertOk();

    $edit = $modelClass::factory()->create($createAttributes);
    $this->get("/admin/{$segment}/{$edit->getKey()}/edit")->assertOk();
})->with('admin access resources');

test('the admin dashboard and the messages inbox follow the same three-way gate', function () {
    $message = ContactMessage::factory()->create();

    // Guest → login.
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('admin.messages.index'))->assertRedirect(route('login'));
    $this->patch(route('admin.messages.read', $message))->assertRedirect(route('login'));

    // Authenticated non-administrator → 403.
    $this->actingAs(User::factory()->create());
    $this->get(route('admin.dashboard'))->assertForbidden();
    $this->get(route('admin.messages.index'))->assertForbidden();
    $this->patch(route('admin.messages.read', $message))->assertForbidden();

    // Administrator → success.
    $this->actingAs(User::factory()->administrator()->create());
    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.messages.index'))->assertOk();
    $this->patch(route('admin.messages.read', $message))->assertRedirect();
});

test('reorder is routed for the six ordered types only', function () {
    // §5.8: `writings` orders by `published_at` and `tags` by `LOWER(name), id`,
    // so neither gets a `reorder` route — for the administrator it is a 404.
    $this->actingAs(User::factory()->administrator()->create());

    $this->patch('/admin/writings/reorder', ['ids' => [1]])->assertNotFound();
    $this->patch('/admin/tags/reorder', ['ids' => [1]])->assertNotFound();

    // The six ordered types do route it.
    $workExperience = WorkExperience::factory()->create(['title' => 'Reorder Route Work Experience', 'employer' => 'Reorder Route Employer', 'start_date' => '2021-01-01']);
    $this->patch('/admin/work-experiences/reorder', ['ids' => [$workExperience->getKey()]])
        ->assertRedirect('/admin/work-experiences');
});

test('a policy denial is a 403, not a 404', function () {
    $workExperience = WorkExperience::factory()->create([
        'title' => 'Denial Work Experience',
        'employer' => 'Denial Employer',
        'start_date' => '2021-01-01',
    ]);

    /*
     * A non-administrator asking for a record that exists is denied by the
     * authorisation layer with a `403` — the route is not hidden behind a
     * `404`, which is what would happen if authorisation were (incorrectly)
     * expressed as "the record is not found". The index, which binds no
     * record, is denied the same way.
     */
    $this->actingAs(User::factory()->create());

    $this->get('/admin/work-experiences')->assertForbidden();
    $this->get('/admin/work-experiences/'.$workExperience->getKey().'/edit')->assertForbidden();

    // The administrator asking for a record that does *not* exist gets a 404,
    // which is what proves a denial and a missing record are distinguishable.
    $this->actingAs(User::factory()->administrator()->create());

    $this->get('/admin/work-experiences/999999/edit')->assertNotFound();
});
