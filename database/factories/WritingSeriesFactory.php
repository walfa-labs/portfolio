<?php

namespace Database\Factories;

use App\Models\WritingSeries;
use App\Support\ContentSlug;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WritingSeries>
 */
class WritingSeriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $words = fake()->unique()->words(3, true);
        $title = Str::headline(is_string($words) ? $words : implode(' ', $words));

        return [
            'title' => $title,
            'slug' => ContentSlug::canonicalize($title),
            'description' => fake()->sentence(12),
        ];
    }
}
