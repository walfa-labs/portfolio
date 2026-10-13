<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Support\ContentSlug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $name = is_string($name) ? $name : implode(' ', $name);

        return [
            'name' => $name,
            'slug' => ContentSlug::canonicalize($name),
            'description' => fake()->sentence(12),
            'is_placeholder' => true,
        ];
    }

    /**
     * Indicate that the tag is real rather than placeholder content.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }
}
