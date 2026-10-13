<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Publication;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publication>
 */
class PublicationFactory extends Factory
{
    /**
     * Invented titles — never a real publication (SC-11).
     *
     * @var list<string>
     */
    private const TITLES = [
        'A Practical Taxonomy of Queue Backpressure Strategies',
        'Measuring Latency Budgets in Multi-Tenant API Gateways',
        'Towards Deterministic Ordering in Distributed Content Stores',
        'An Empirical Study of Cache Invalidation at the Edge',
        'Cost Models for Incremental Static Rendering',
        'Observability Patterns for Short-Lived Batch Workloads',
        'A Survey of Schema Migration Safety Nets',
        'Reading Performance of Server-Rendered Component Trees',
        'Rate Limiting Under Bursty Tenant Traffic',
        'Reproducible Builds for Polyglot Repositories',
        'Type Safety at the Client-Server Boundary',
        'Failover Strategies for Single-Writer Datastores',
    ];

    /**
     * Invented venues — never a real journal or conference (SC-11).
     *
     * @var list<string>
     */
    private const VENUES = [
        'Annals of Applied Computation',
        'Interdisciplinary Review of Computing',
        'International Review of Software Practice',
        'Journal of Applied Interface Research',
        'Journal of Distributed Craft',
        'Journal of Reliable Systems Design',
        'Journal of Scalable Infrastructure',
        'Proceedings of the Aurora Systems Symposium',
        'Proceedings of the Brightpath Platform Conference',
        'Proceedings of the Cobalt Ridge Colloquium',
        'Quarterly Journal of Data Engineering',
        'Transactions on Practical Software Analysis',
    ];

    /**
     * Invented author display names — never a real person (SC-11).
     *
     * @var list<string>
     */
    private const AUTHORS = [
        'Aldous Quillstone',
        'Bertram Larkfell',
        'Cassia Vantrell',
        'Dorian Merrowby',
        'Elspeth Cairnwood',
        'Fennimore Ashgate',
        'Greta Hollowbrook',
        'Halvard Stonewreath',
        'Isolde Marrowgate',
        'Jasper Thornbury',
        'Linnet Fairweather',
        'Marlow Greystone',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuedOn = fake()->dateTimeBetween('-10 years', 'now');

        return [
            'title' => fake()->randomElement(self::TITLES),
            'summary' => fake()->sentence(12),
            'body' => fake()->paragraphs(2, true),
            'status' => ContentStatus::Draft,
            'published_at' => null,
            'is_placeholder' => true,
            'meta_title' => null,
            'meta_description' => null,
            'sort_order' => 0,
            'venue' => fake()->randomElement(self::VENUES),
            'authors' => $this->authorList(),
            'doi' => sprintf(
                '10.%d/%s.%s.%d',
                fake()->numberBetween(10000, 99999),
                fake()->lexify('????'),
                $issuedOn->format('Y'),
                fake()->numberBetween(100, 9999),
            ),
            'issued_on' => $issuedOn,
        ];
    }

    /**
     * Indicate that the publication is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the publication is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the publication is real rather than placeholder content.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }

    /**
     * Attach two to four tags to the publication.
     */
    public function tagged(): static
    {
        return $this->afterCreating(function (Publication $publication): void {
            $publication->tags()->attach(
                Tag::factory()->count(rand(2, 4))->create()->modelKeys(),
            );
        });
    }

    /**
     * Build a comma-separated display-name list of one to four authors.
     */
    private function authorList(): string
    {
        $names = fake()->randomElements(self::AUTHORS, fake()->numberBetween(1, 4));

        return implode(', ', $names);
    }
}
