<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Invented project names — never a real project or product (SC-11).
     *
     * @var list<string>
     */
    private const TITLES = [
        'Aurora Ledger',
        'Brightpath Console',
        'Cobalt Ridge Deployer',
        'Driftwood Queue',
        'Emberline Router',
        'Fathom Cache',
        'Glasshouse Renderer',
        'Hollowbrook Indexer',
        'Ironbark Schema',
        'Junction Watchtower',
        'Kestrel Migrator',
        'Lanternpath Crawler',
        'Meridian Scheduler',
        'Northwind Thumbnailer',
        'Oregano Formatter',
        'Pinewood Notifier',
        'Quillstone Parser',
        'Riverbend Gateway',
        'Silverbrook Metrics',
        'Tanglewood Fetcher',
        'Umberlight Diff',
        'Vantage Bulkhead',
        'Willowmere Search',
        'Yarrow Signal',
    ];

    /**
     * Rotation counter that makes the default `is_featured` value deterministic.
     *
     * Two rows in every three are featured, so any batch of two or more projects
     * holds at least a third featured without a random draw — §2.3 needs three to
     * six featured projects for the home page's "Selected work" section, and a
     * random default would make the seeder's "≥ 3 featured" assertion flaky.
     */
    private static int $featuredSequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->randomElement(self::TITLES);
        $slug = Str::slug($title);
        $startedOn = fake()->dateTimeBetween('-8 years', '-1 month');

        return [
            'title' => $title,
            'summary' => fake()->sentence(12),
            'body' => fake()->paragraphs(2, true),
            'status' => ContentStatus::Draft,
            'published_at' => null,
            'is_placeholder' => true,
            'meta_title' => null,
            'meta_description' => null,
            'sort_order' => 0,
            'repo_url' => "https://git.example.com/portfolio/{$slug}",
            'live_url' => "https://{$slug}.example.com",
            'started_on' => $startedOn,
            'ended_on' => fake()->boolean(50)
                ? fake()->dateTimeBetween($startedOn, 'now')
                : null,
            'is_featured' => self::$featuredSequence++ % 3 !== 2,
        ];
    }

    /**
     * Indicate that the project is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the project is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the project is featured on the home page.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Indicate that the project is real rather than placeholder content.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }

    /**
     * Attach two to four tags to the project.
     */
    public function tagged(): static
    {
        return $this->afterCreating(function (Project $project): void {
            $project->tags()->attach(
                Tag::factory()->count(rand(2, 4))->create()->modelKeys(),
            );
        });
    }
}
