<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Tag;
use App\Models\WorkExperience;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkExperience>
 */
class WorkExperienceFactory extends Factory
{
    /**
     * Invented employers — never a real organisation (SC-11).
     *
     * @var list<string>
     */
    private const EMPLOYERS = [
        'Cairnbrook Systems',
        'Emberlight Technologies',
        'Fernhollow Digital',
        'Glasswing Software',
        'Harborlight Analytics',
        'Ironwood Circuit Works',
        'Lumenpath Data',
        'Pinebluff Platform Group',
        'Quarryside Engineering',
        'Sablebrook Cloud Services',
        'Thistlefield Labs',
        'Willowmere Interactive',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $employer = fake()->randomElement(self::EMPLOYERS);
        $startDate = fake()->dateTimeBetween('-12 years', '-1 year');
        $endDate = fake()->boolean(25)
            ? null
            : fake()->dateTimeBetween($startDate, 'now');

        return [
            'title' => fake()->jobTitle(),
            'summary' => fake()->sentence(12),
            'body' => fake()->paragraphs(2, true),
            'status' => ContentStatus::Draft,
            'published_at' => null,
            'is_placeholder' => true,
            'employer' => $employer,
            'employer_url' => 'https://'.Str::slug($employer).'.example',
            'location' => fake()->city(),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate?->format('Y-m-d'),
            'highlights' => fake()->sentences(fake()->numberBetween(2, 4)),
        ];
    }

    /**
     * Indicate that the entry is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the entry is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the entry is real rather than placeholder content.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }

    /**
     * Indicate that the role is current — it renders as "Present".
     */
    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'end_date' => null,
        ]);
    }

    /**
     * Attach two to four tags to the entry.
     */
    public function tagged(): static
    {
        return $this->afterCreating(function (WorkExperience $workExperience): void {
            $workExperience->tags()->attach(Tag::factory()->count(rand(2, 4))->create());
        });
    }
}
