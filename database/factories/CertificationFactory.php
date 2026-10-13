<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certification>
 */
class CertificationFactory extends Factory
{
    /**
     * Invented issuers — never a real organisation (SC-11).
     *
     * @var list<string>
     */
    private const ISSUERS = [
        'Aurora Systems Institute',
        'Brightpath Technology Academy',
        'Cobalt Ridge Certification Board',
        'Meridian Cloud Institute',
        'Northwind Engineering Guild',
        'Quillstone Data Academy',
        'Riverbend Software Council',
        'Silverbrook Platform Institute',
        'Tanglewood Systems Academy',
        'Vantage Point Engineering Board',
    ];

    /**
     * Invented credential titles — never a real certification or trademark (SC-11).
     *
     * @var list<string>
     */
    private const TITLES = [
        'Certified Platform Engineer',
        'Advanced Cloud Architect',
        'Professional Site Reliability Engineer',
        'Certified Data Pipeline Engineer',
        'Associate Software Security Specialist',
        'Certified Container Orchestration Engineer',
        'Professional Observability Practitioner',
        'Advanced Database Performance Specialist',
        'Certified API Design Engineer',
        'Professional Distributed Systems Architect',
        'Certified Infrastructure Automation Engineer',
        'Associate Web Accessibility Specialist',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuer = fake()->randomElement(self::ISSUERS);
        $credentialId = strtoupper(fake()->bothify('??-####-####'));

        return [
            'title' => fake()->randomElement(self::TITLES),
            'summary' => fake()->paragraph(),
            'body' => fake()->paragraph(),
            'status' => ContentStatus::Draft,
            'published_at' => null,
            'is_placeholder' => true,
            'meta_title' => null,
            'meta_description' => null,
            'sort_order' => 0,
            'issuer' => $issuer,
            'credential_id' => $credentialId,
            'credential_url' => 'https://'.Str::slug($issuer).'.example/credentials/'.$credentialId,
            'issued_on' => fake()->dateTimeBetween('-6 years', 'now'),
            'expires_on' => null,
        ];
    }

    /**
     * Indicate that the certification is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the certification is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the certification is real rather than placeholder content.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }

    /**
     * Indicate that the certification expires inside the 90-day badge window.
     */
    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_on' => now()->addDays(rand(1, 89)),
        ]);
    }

    /**
     * Indicate that the certification has already expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_on' => now()->subDays(rand(1, 365)),
        ]);
    }

    /**
     * Attach two to four tags to the certification.
     */
    public function tagged(): static
    {
        return $this->afterCreating(function (Certification $certification): void {
            $certification->tags()->attach(
                Tag::factory()->count(rand(2, 4))->create()->modelKeys(),
            );
        });
    }
}
