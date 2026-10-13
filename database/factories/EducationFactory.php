<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Education;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Education>
 */
class EducationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-20 years', '-3 years');
        /** @var \DateTime|null $endDate */
        $endDate = fake()->optional(0.85)->dateTimeBetween($startDate, 'now');

        return [
            'title' => fake()->words(4, true),
            'summary' => fake()->optional(0.8)->paragraph(),
            'body' => fake()->optional(0.6)->paragraphs(3, true),
            'status' => ContentStatus::Draft->value,
            'published_at' => null,
            'is_placeholder' => true,
            'meta_title' => null,
            'meta_description' => null,
            'sort_order' => 0,
            'institution' => fake()->company(),
            'institution_url' => fake()->optional(0.7)->url(),
            'field_of_study' => fake()->optional(0.9)->words(3, true),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate?->format('Y-m-d'),
        ];
    }

    /**
     * Indicate that the education is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the education is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft->value,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the education is a real (non-placeholder) record.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }

    /**
     * Indicate that the education has tags attached.
     */
    public function tagged(): static
    {
        return $this->afterCreating(fn (Education $education) => $education->tags()->attach(
            Tag::factory()->count(rand(2, 4))->create()
        ));
    }
}
