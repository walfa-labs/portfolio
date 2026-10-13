<?php

use App\Models\User;
use App\Models\WorkExperience;
use App\Policies\WorkExperiencePolicy;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| One policy per model, shared by the API and the admin UI (§5.7, §11.3)
|--------------------------------------------------------------------------
|
| §5.7 pins exactly nine policies and has the API group's write verbs
| authorised by the *same* policies the admin controllers call through
| `$this->authorize(...)`. The cheapest guard against the two surfaces
| drifting apart is to flip one policy's outcome and watch both surfaces
| change together: if the API and the admin UI consulted different policies,
| flipping one would leave the other untouched and this test would fail.
|
| The flip is a partial mock of `WorkExperiencePolicy`, bound in the
| container so the Gate's `resolvePolicy()` — which does
| `container->make($policyClass)` — hands back the mock instead of the real
| policy. No application file is touched, and the mock dies with the test.
|
| `educations` is the control: it keeps succeeding on both surfaces while
| `work-experiences` is denied, which proves the flip is scoped to one
| model's policy and not a global gate.
|
*/

/**
 * A JSON:API request document for one work experience.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, array<string, mixed>>
 */
function policySharingDocument(array $attributes): array
{
    return ['data' => ['type' => 'work-experiences', 'attributes' => $attributes]];
}

test('flipping a policy changes the API and the admin UI together', function () {
    $admin = User::factory()->administrator()->create();
    $this->actingAs($admin);

    $workExperience = WorkExperience::factory()->create([
        'title' => 'Policy Sharing Work Experience',
        'employer' => 'Policy Sharing Employer',
        'start_date' => '2021-01-01',
    ]);

    /*
     * Baseline: both surfaces authorise the administrator. The API answers
     * `201`, the admin UI redirects `302` to the index.
     */
    $this->postJson(
        '/api/v1/work-experiences',
        policySharingDocument(['title' => 'Baseline API', 'employer' => 'Baseline', 'start_date' => '2021-01-01']),
        ['Content-Type' => 'application/vnd.api+json'],
    )->assertCreated();

    $this->post('/admin/work-experiences', [
        'title' => 'Baseline Admin',
        'employer' => 'Baseline',
        'start_date' => '2021-01-01',
    ])->assertRedirect('/admin/work-experiences');

    /*
     * Flip every ability the policy defines to `false`. The Gate drops the
     * class-string first argument before calling the policy method, so each
     * method is reached with just the authenticated user.
     */
    $this->partialMock(WorkExperiencePolicy::class, function (MockInterface $mock) {
        $mock->shouldReceive('viewAny')->andReturn(false);
        $mock->shouldReceive('view')->andReturn(false);
        $mock->shouldReceive('create')->andReturn(false);
        $mock->shouldReceive('update')->andReturn(false);
        $mock->shouldReceive('delete')->andReturn(false);
        $mock->shouldReceive('publish')->andReturn(false);
        $mock->shouldReceive('unpublish')->andReturn(false);
        $mock->shouldReceive('reorder')->andReturn(false);
    });

    /*
     * The admin UI now denies every verb. The `admin` middleware still passes
     * (it reads `isAdministrator()`, not the policy), so each `403` is the
     * per-resource policy the controller consults — the layer this file is
     * here to prove is shared.
     */
    $this->get('/admin/work-experiences')->assertForbidden();
    $this->get('/admin/work-experiences/create')->assertForbidden();
    $this->post('/admin/work-experiences', [
        'title' => 'Flipped Admin',
        'employer' => 'Flipped',
        'start_date' => '2021-01-01',
    ])->assertForbidden();
    $this->get('/admin/work-experiences/'.$workExperience->getKey().'/edit')->assertForbidden();
    $this->patch('/admin/work-experiences/'.$workExperience->getKey(), ['title' => 'Flipped Update'])->assertForbidden();
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/admin/work-experiences/'.$workExperience->getKey())
        ->assertForbidden();
    $this->patch('/admin/work-experiences/'.$workExperience->getKey().'/publish')->assertForbidden();
    $this->patch('/admin/work-experiences/'.$workExperience->getKey().'/unpublish')->assertForbidden();
    $this->patch('/admin/work-experiences/reorder', ['ids' => [$workExperience->getKey()]])->assertForbidden();

    /*
     * The API denies the same model through the same policy. `create` is
     * authorised by the store form request, `update` by the update form
     * request and `delete` by the controller's `remove()` — three different
     * call sites, one policy.
     */
    $this->postJson(
        '/api/v1/work-experiences',
        policySharingDocument(['title' => 'Flipped API', 'employer' => 'Flipped', 'start_date' => '2021-01-01']),
        ['Content-Type' => 'application/vnd.api+json'],
    )->assertForbidden();

    $this->patchJson(
        '/api/v1/work-experiences/'.$workExperience->getKey(),
        policySharingDocument(['title' => 'Flipped API Update']),
        ['Content-Type' => 'application/vnd.api+json'],
    )->assertForbidden();

    $this->deleteJson('/api/v1/work-experiences/'.$workExperience->getKey())->assertForbidden();

    /*
     * The control: `educations` is untouched by the flip and still succeeds
     * on both surfaces. If the two surfaces did not share one policy per
     * model, this is where the drift would show.
     */
    $this->postJson(
        '/api/v1/educations',
        ['data' => ['type' => 'educations', 'attributes' => ['title' => 'Control Education', 'institution' => 'Control']]],
        ['Content-Type' => 'application/vnd.api+json'],
    )->assertCreated();

    $this->post('/admin/educations', ['title' => 'Control Admin Education', 'institution' => 'Control'])
        ->assertRedirect('/admin/educations');
});
