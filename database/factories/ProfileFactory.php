<?php

namespace Database\Factories;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * Availability lines that fit the 50-character column.
     *
     * @var list<string>
     */
    private const AVAILABILITY = [
        'Open to contract work',
        'Open to full-time roles',
        'Not taking new work',
    ];

    /**
     * Role headlines that fit the 150-character column.
     *
     * @var list<string>
     */
    private const ROLE_HEADLINES = [
        'Software Engineer',
        'Backend Engineer',
        'Platform Engineer',
        'Full-stack Developer',
        'Engineering Lead',
        'Principal Engineer',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role_headline' => fake()->randomElement(self::ROLE_HEADLINES),
            'positioning_sentence' => Str::limit(fake()->sentence(12), 160, ''),
            'location' => fake()->city(),
            'availability' => fake()->randomElement(self::AVAILABILITY),
            'email' => fake()->safeEmail(),
            'github_url' => 'https://github.example.com/'.fake()->userName(),
            'linkedin_url' => 'https://linkedin.example.com/in/'.fake()->userName(),
            'orcid_url' => 'https://orcid.example.org/0000-0000-0000-0000',
            'years_experience_start' => fake()->dateTimeBetween('-15 years', '-3 years')->format('Y-m-d'),
        ];
    }
}
