<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Tag;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Writing>
 */
class WritingFactory extends Factory
{
    /**
     * Invented engineering topics — never a real article or organisation (SC-11).
     *
     * @var list<string>
     */
    private const TOPICS = [
        'queue backlogs',
        'cache invalidation',
        'schema migrations',
        'feature flags',
        'index selectivity',
        'retry budgets',
        'log noise',
        'form validation',
        'background jobs',
        'database transactions',
        'CSS custom properties',
        'progressive enhancement',
        'rate limiting',
        'search ranking',
        'error budgets',
        'static analysis',
        'image pipelines',
        'soft deletes',
        'time zone handling',
        'pagination cursors',
        'accessibility audits',
        'deployment rollbacks',
        'test data builders',
        'API versioning',
    ];

    /**
     * Title frames paired with a topic to make a plausible article title.
     *
     * @var list<string>
     */
    private const TITLE_FRAMES = [
        'Notes on %s',
        'What %s taught me',
        'A short field guide to %s',
        'Debugging %s',
        'Why %s is a design decision',
        'Reading %s twice',
        'The case against clever %s',
        'Measuring %s',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => sprintf(
                fake()->randomElement(self::TITLE_FRAMES),
                fake()->randomElement(self::TOPICS),
            ),
            'summary' => fake()->sentence(12),
            'body' => $this->markdownBody(),
            'status' => ContentStatus::Draft,
            'published_at' => null,
            'is_placeholder' => true,
            'meta_title' => null,
            'meta_description' => null,
            'sort_order' => 0,
            'excerpt' => Str::limit(fake()->sentence(16), 280, ''),
            'series_id' => null,
            'og_image_path' => null,
        ];
    }

    /**
     * Indicate that the writing is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the writing is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the writing is real rather than placeholder content.
     */
    public function real(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => false,
        ]);
    }

    /**
     * Attach two to four tags to the writing.
     */
    public function tagged(): static
    {
        return $this->afterCreating(function (Writing $writing): void {
            $writing->tags()->attach(
                Tag::factory()->count(rand(2, 4))->create()->modelKeys(),
            );
        });
    }

    /**
     * Build a plausible Markdown article body.
     *
     * The body is stored as Markdown source only; `body_html` and
     * `reading_time_minutes` are derived by the model on save (§2.3).
     */
    private function markdownBody(): string
    {
        $paragraphs = [
            fake()->paragraph(6),
            fake()->paragraph(5),
            fake()->paragraph(4),
        ];

        $bullets = [
            fake()->sentence(),
            fake()->sentence(),
            fake()->sentence(),
        ];

        return implode("\n\n", [
            '## '.rtrim(fake()->sentence(4), '.'),
            $paragraphs[0],
            '- '.implode("\n- ", $bullets),
            '## '.rtrim(fake()->sentence(4), '.'),
            $paragraphs[1],
            "```php\n".$this->codeSample()."\n```",
            $paragraphs[2],
        ]);
    }

    /**
     * Build a short, invented PHP snippet for the body's fenced code block.
     */
    private function codeSample(): string
    {
        return implode("\n", [
            'public function handle(Job $job): void',
            '{',
            '    Cache::forget("report:".$job->id);',
            '',
            '    $job->markProcessed();',
            '}',
        ]);
    }
}
