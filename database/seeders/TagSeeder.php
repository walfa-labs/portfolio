<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Support\ContentSlug;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /**
     * The shared tag vocabulary.
     *
     * One list serves every content type — a project tagged `laravel` and a
     * writing tagged `laravel` land on the same tag page (§2.1 item 3). Tag
     * names are technology and discipline labels, never an organisation.
     *
     * Slugs are not listed here: they are derived from the name on save, so a
     * hand-written slug could silently drift from the derived one and break the
     * `updateOrCreate` lookup on a re-seed. See `run()`.
     *
     * @var list<array{name: string, description: string}>
     */
    private const TAGS = [
        ['name' => 'Laravel', 'description' => 'PHP application framework.'],
        ['name' => 'PHP', 'description' => 'The language behind the backend.'],
        ['name' => 'Vue', 'description' => 'Progressive JavaScript framework.'],
        ['name' => 'TypeScript', 'description' => 'Typed JavaScript.'],
        ['name' => 'Tailwind CSS', 'description' => 'Utility-first styling.'],
        ['name' => 'Inertia', 'description' => 'Server-driven single-page apps.'],
        ['name' => 'MySQL', 'description' => 'Relational storage and query planning.'],
        ['name' => 'Redis', 'description' => 'Caching, queues and rate limiting.'],
        ['name' => 'Docker', 'description' => 'Reproducible local environments.'],
        ['name' => 'Linux', 'description' => 'Servers, shells and processes.'],
        ['name' => 'API design', 'description' => 'Resource modelling and HTTP semantics.'],
        ['name' => 'HTTP', 'description' => 'Caching, content negotiation and status codes.'],
        ['name' => 'Testing', 'description' => 'Unit, feature and browser-level checks.'],
        ['name' => 'Accessibility', 'description' => 'Keyboard, screen reader and contrast support.'],
        ['name' => 'Performance', 'description' => 'Budgets, profiling and slow queries.'],
        ['name' => 'Security', 'description' => 'Authorisation, secrets and input handling.'],
        ['name' => 'CI/CD', 'description' => 'Pipelines that gate a merge.'],
        ['name' => 'Observability', 'description' => 'Logs, metrics and traces that answer questions.'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TAGS as $tag) {
            $slug = ContentSlug::canonicalize($tag['name']);

            Tag::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $tag['name'],
                    'description' => $tag['description'],
                    'is_placeholder' => true,
                ],
            );
        }
    }
}
