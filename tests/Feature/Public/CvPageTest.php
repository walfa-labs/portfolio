<?php

use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Media;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkExperience;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| CV (§3.6)
|--------------------------------------------------------------------------
|
| One printable page merging experience, education, certifications,
| publications, projects and awards in reverse-chronological order, plus the
| profile summary and the skill vocabulary. `/cv/download` is the only file
| response and answers 404 when nothing has been uploaded.
|
| The response body is the client-side shell during the suite (no SSR server
| listens on 127.0.0.1:13714), so the two markup-level facts — the print
| styling and the download link — are asserted against the component source
| and the route table rather than against rendered HTML.
|
*/

function cvPageComponentSource(): string
{
    return File::get(base_path('resources/js/pages/public/Cv.vue'));
}

test('the CV merges the six sections in the §3.6 order, each reverse-chronological', function () {
    $oldestRole = WorkExperience::factory()->published()->create([
        'title' => 'Oldest Role',
        'start_date' => '2015-01-01',
        'end_date' => '2019-12-31',
    ]);
    $middleRole = WorkExperience::factory()->published()->create([
        'title' => 'Middle Role',
        'start_date' => '2020-01-01',
        'end_date' => '2022-12-31',
    ]);
    $newestRole = WorkExperience::factory()->published()->create([
        'title' => 'Newest Role',
        'start_date' => '2023-01-01',
        'end_date' => '2024-12-31',
    ]);

    WorkExperience::factory()->draft()->create([
        'title' => 'Draft Role',
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
    ]);

    $olderDegree = Education::factory()->published()->create([
        'title' => 'Older Degree',
        'start_date' => '2011-09-01',
        'end_date' => '2014-06-30',
    ]);
    $newerDegree = Education::factory()->published()->create([
        'title' => 'Newer Degree',
        'start_date' => '2015-09-01',
        'end_date' => '2018-06-30',
    ]);

    $olderCertification = Certification::factory()->published()->create([
        'title' => 'Older Certification',
        'issued_on' => '2021-01-01',
    ]);
    $newerCertification = Certification::factory()->published()->create([
        'title' => 'Newer Certification',
        'issued_on' => '2023-01-01',
    ]);

    $olderPublication = Publication::factory()->published()->create([
        'title' => 'Older Publication',
        'issued_on' => '2018-01-01',
    ]);
    $newerPublication = Publication::factory()->published()->create([
        'title' => 'Newer Publication',
        'issued_on' => '2022-01-01',
    ]);

    $olderProject = Project::factory()->published()->create([
        'title' => 'Older Project',
        'started_on' => '2016-01-01',
        'ended_on' => '2016-12-31',
    ]);
    $newerProject = Project::factory()->published()->create([
        'title' => 'Newer Project',
        'started_on' => '2021-01-01',
        'ended_on' => '2021-12-31',
    ]);

    $olderAward = Award::factory()->published()->create([
        'title' => 'Older Award',
        'awarded_at' => '2019-01-01',
    ]);
    $newerAward = Award::factory()->published()->create([
        'title' => 'Newer Award',
        'awarded_at' => '2024-01-01',
    ]);

    $response = $this->get(route('cv'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Cv')
        ->has('sections', 6)
        ->where('sections.0.key', 'experience')
        ->where('sections.0.title', 'Experience')
        ->where('sections.1.key', 'education')
        ->where('sections.1.title', 'Education')
        ->where('sections.2.key', 'certifications')
        ->where('sections.2.title', 'Certifications')
        ->where('sections.3.key', 'publications')
        ->where('sections.3.title', 'Publications')
        ->where('sections.4.key', 'projects')
        ->where('sections.4.title', 'Projects')
        ->where('sections.5.key', 'awards')
        ->where('sections.5.title', 'Awards')
        ->has('sections.0.items', 3)
        ->where('sections.0.items.0.id', $newestRole->id)
        ->where('sections.0.items.1.id', $middleRole->id)
        ->where('sections.0.items.2.id', $oldestRole->id)
        ->has('sections.1.items', 2)
        ->where('sections.1.items.0.id', $newerDegree->id)
        ->where('sections.1.items.1.id', $olderDegree->id)
        ->has('sections.2.items', 2)
        ->where('sections.2.items.0.id', $newerCertification->id)
        ->where('sections.2.items.1.id', $olderCertification->id)
        ->has('sections.3.items', 2)
        ->where('sections.3.items.0.id', $newerPublication->id)
        ->where('sections.3.items.1.id', $olderPublication->id)
        ->has('sections.4.items', 2)
        ->where('sections.4.items.0.id', $newerProject->id)
        ->where('sections.4.items.1.id', $olderProject->id)
        ->has('sections.5.items', 2)
        ->where('sections.5.items.0.id', $newerAward->id)
        ->where('sections.5.items.1.id', $olderAward->id)
    );
});

test('the CV carries the profile summary and the published skill vocabulary', function () {
    Profile::factory()->create([
        'positioning_sentence' => 'Ten years of keeping small platforms boring and cheap to change.',
    ]);

    $laravel = Tag::factory()->create(['name' => 'Laravel']);
    $mysql = Tag::factory()->create(['name' => 'MySQL']);
    $redis = Tag::factory()->create(['name' => 'Redis']);
    $draftOnly = Tag::factory()->create(['name' => 'Zebra']);

    $firstProject = Project::factory()->published()->create();
    $secondProject = Project::factory()->published()->create();

    $firstProject->tags()->attach($laravel);
    $secondProject->tags()->attach($laravel);
    $firstProject->tags()->attach($mysql);
    $secondProject->tags()->attach($redis);

    $draftProject = Project::factory()->draft()->create();
    $draftProject->tags()->attach($draftOnly);

    $response = $this->get(route('cv'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary', 'Ten years of keeping small platforms boring and cheap to change.')
        ->has('skills', 3)
        ->where('skills.0', 'Laravel')
        ->where('skills.1', 'MySQL')
        ->where('skills.2', 'Redis')
    );
});

test('the CV is noindex by default and follows the indexable switch', function () {
    config(['app.indexable' => false]);

    $this->get(route('cv'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('noindex', true)
    );

    config(['app.indexable' => true]);

    $this->get(route('cv'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('noindex', false)
    );
});

test('the CV links to the download route for a user who owns a processed CV', function () {
    $user = User::factory()->create();

    Media::factory()->create([
        'mediable_type' => 'user',
        'mediable_id' => $user->id,
        'collection' => 'cv',
        'status' => 'processed',
    ]);

    $response = $this->actingAs($user)->get(route('cv'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('downloadUrl', route('cv.download'))
    );

    expect(cvPageComponentSource())->toContain(':href="downloadUrl"');
});

test('the printable artefact is the print stylesheet, not a generated PDF', function () {
    $source = cvPageComponentSource();

    expect($source)->toContain('print:hidden')
        ->and($source)->toContain('print:break-inside-avoid');

    expect(Route::has('cv.pdf'))->toBeFalse();

    $pdfRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'pdf'))
        ->values()
        ->all();

    expect($pdfRoutes)->toBe([]);
});

test('the CV download answers 404 cleanly when no CV media exists', function () {
    expect(Media::query()->count())->toBe(0);

    $response = $this->get(route('cv.download'));

    $response->assertNotFound();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Error')
        ->where('status', 404)
    );
});
