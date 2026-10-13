<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Award;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Award>
 */
class AwardFactory extends Factory
{
    /**
     * Invented award titles — never a real prize or trademark (SC-11).
     *
     * @var list<string>
     */
    private const TITLES = [
        'Outstanding Platform Contribution',
        'Excellence in Reliability Engineering',
        'Distinguished Open Source Maintainer',
        'Innovation in Developer Tooling',
        'Rising Engineer of the Year',
        'Technical Leadership Recognition',
        'Best Internal Developer Platform',
        'Community Mentor Award',
        'Architecture Excellence Prize',
        'Data Engineering Achievement',
        'Accessibility Champion Award',
        'Longstanding Craftsmanship Citation',
    ];

    /**
     * Invented awarding bodies — never a real organisation (SC-11).
     *
     * @var list<string>
     */
    private const AWARDING_BODIES = [
        'Aurora Engineering Society',
        'Brightpath Technology Council',
        'Cobalt Ridge Software Guild',
        'Driftwood Systems Foundation',
        'Emberline Developers Alliance',
        'Fathom Institute of Computing',
        'Glasshouse Platform Association',
        'Hollowbrook Craft Board',
        'Ironbark Engineering Federation',
        'Junction Open Source Trust',
        'Kestrel Innovation Committee',
        'Lanternpath Digital Academy',
        'Meridian Software Institute',
        'Northwind Systems Society',
        'Oregano Computing Council',
        'Pinewood Technology League',
        'Quillstone Engineering Circle',
        'Riverbend Software Foundation',
        'Silverbrook Developers Guild',
        'Tanglewood Platform Society',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(self::TITLES),
            'summary' => fake()->sentence(12),
            'body' => fake()->paragraph(),
            'status' => ContentStatus::Draft,
            'published_at' => null,
            'is_placeholder' => true,
            'meta_title' => null,
            'meta_description' => null,
            'sort_order' => 0,
            'awarding_body' => fake()->randomElement(self::AWARDING_BODIES),
            'awarded_at' => fake()->dateTimeBetween('-10 years', 'now'),
        ];
    }

    /**
     * Indicate that the award is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the award is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the award is real rather than placeholder content.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }

    /**
     * Attach two to four tags to the award.
     */
    public function tagged(): static
    {
        return $this->afterCreating(function (Award $award): void {
            $award->tags()->attach(
                Tag::factory()->count(rand(2, 4))->create()->modelKeys(),
            );
        });
    }
}
