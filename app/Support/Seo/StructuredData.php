<?php

namespace App\Support\Seo;

use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The server-side builder for every page's JSON-LD (§3.9).
 *
 * One `@graph` document per page, built here in PHP so it is unit-testable
 * without a browser, and passed to `SeoHead.vue` as its `jsonLd` prop, which
 * renders one `<script type="application/ld+json">` per element.
 *
 * **Every member builder returns a *list* of graph members**, so a page composes
 * its document by spreading them into {@see self::graph()}:
 *
 * ```php
 * $jsonLd = StructuredData::graph([
 *     ...StructuredData::base(),
 *     ...StructuredData::collectionPage(
 *         'Projects',
 *         StructuredData::url('projects.index'),
 *         StructuredData::items($projects),
 *         'Selected software projects.',
 *     ),
 *     ...StructuredData::breadcrumbs($breadcrumbs),
 * ]);
 * ```
 *
 * That shape is what makes {@see self::breadcrumbs()} composable: it returns an
 * empty list on `/`, which carries no `BreadcrumbList`, so the same spread works
 * on every page. Error pages get no JSON-LD at all — pass `[]`.
 *
 * `Person` and `WebSite` are on every public page (§3.9, "Every page (from
 * `PublicLayout`)"). The layout cannot build them, because the graph is
 * server-side, so every public controller starts from {@see self::base()}.
 */
class StructuredData
{
    /**
     * The schema.org context every `@graph` document declares.
     */
    public const CONTEXT = 'https://schema.org';

    /**
     * Assemble the page's single `@graph` document.
     *
     * The result is a one-element list, so it can be handed straight to
     * `SeoHead`'s `jsonLd` prop. Everything else in this class returns a list of
     * members ready to spread into `$members`.
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    public static function graph(array $members): array
    {
        return [[
            '@context' => self::CONTEXT,
            '@graph' => $members,
        ]];
    }

    /**
     * The two members every public page carries: `Person` and `WebSite`.
     *
     * @return list<array<string, mixed>>
     */
    public static function base(): array
    {
        return [self::person(), self::website()];
    }

    /**
     * The `Person` node, built from the single `profile` row.
     *
     * `hasOccupation` carries the role history and `worksFor` the current
     * employer: schema.org has no "work experience" type, so those two fields
     * are where the experience semantics live (§3.9).
     *
     * @return array<string, mixed>
     */
    public static function person(): array
    {
        $profile = Profile::query()->first();
        $roles = WorkExperience::query()
            ->published()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
        $current = $roles->first(fn (WorkExperience $role): bool => $role->end_date === null);

        $person = self::pruneEmpty([
            '@type' => 'Person',
            '@id' => self::siteUrl().'#person',
            'name' => self::personName($profile),
            'jobTitle' => $profile?->role_headline,
            'url' => self::siteUrl(),
            'worksFor' => $current === null ? null : self::pruneEmpty([
                '@type' => 'Organization',
                'name' => $current->employer,
                'url' => $current->employer_url,
            ]),
            'alumniOf' => self::schools(),
            'hasCredential' => self::credentials(),
            'hasOccupation' => $roles
                ->map(fn (WorkExperience $role): array => self::pruneEmpty([
                    '@type' => 'Occupation',
                    'name' => $role->title,
                    'description' => $role->summary,
                ]))
                ->all(),
            'award' => Award::query()->published()->orderByDesc('awarded_at')->pluck('title')->all(),
            'knowsAbout' => self::topics(),
            'sameAs' => array_values(array_filter([
                $profile?->github_url,
                $profile?->linkedin_url,
                $profile?->orcid_url,
            ])),
        ]);

        return $person;
    }

    /**
     * The `WebSite` node, with the `SearchAction` that advertises `/search`.
     *
     * @return array<string, mixed>
     */
    public static function website(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::siteUrl().'#website',
            'url' => self::siteUrl(),
            'name' => (string) config('app.name'),
            'inLanguage' => app()->getLocale(),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => self::siteUrl().'/search?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * The `ProfilePage` member for `/` and `/cv`.
     *
     * @return list<array<string, mixed>>
     */
    public static function profilePage(string $url): array
    {
        return [[
            '@type' => 'ProfilePage',
            '@id' => $url.'#profilepage',
            'url' => $url,
            'name' => (string) config('app.name'),
            'isPartOf' => ['@id' => self::siteUrl().'#website'],
            'mainEntity' => ['@id' => self::siteUrl().'#person'],
        ]];
    }

    /**
     * The `CollectionPage` + `ItemList` pair a content list carries.
     *
     * @param  list<array<string, mixed>>  $items  from {@see self::items()}
     * @return list<array<string, mixed>>
     */
    public static function collectionPage(string $name, string $url, array $items, ?string $description = null): array
    {
        $listId = $url.'#itemlist';

        return [
            self::pruneEmpty([
                '@type' => 'CollectionPage',
                '@id' => $url.'#collectionpage',
                'url' => $url,
                'name' => $name,
                'description' => $description,
                'isPartOf' => ['@id' => self::siteUrl().'#website'],
                'mainEntity' => ['@id' => $listId],
            ]),
            self::pruneEmpty([
                '@type' => 'ItemList',
                '@id' => $listId,
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ]),
        ];
    }

    /**
     * Build positioned `ListItem`s for a whole list.
     *
     * Order is the caller's: the items are numbered in the order they arrive,
     * which is the order the page renders them in.
     *
     * @param  iterable<int, Model>  $models
     * @return list<array<string, mixed>>
     */
    public static function items(iterable $models): array
    {
        $items = [];
        $position = 1;

        foreach ($models as $model) {
            $items[] = self::item($model, $position++);
        }

        return $items;
    }

    /**
     * Build one positioned `ListItem`, typed by the model it wraps.
     *
     * Certifications, publications and projects become
     * `EducationalOccupationalCredential`, `ScholarlyArticle` and
     * `SoftwareSourceCode` respectively. Everything else — including awards,
     * whose description carries the awarding body and year — stays a bare
     * `ListItem`, because schema.org has no type for it and §3.9 forbids
     * fabricating one.
     *
     * @return array<string, mixed>
     */
    public static function item(Model $model, int $position): array
    {
        return match (true) {
            $model instanceof Certification => self::typedItem($position, self::pruneEmpty([
                '@type' => 'EducationalOccupationalCredential',
                'name' => $model->title,
                'description' => $model->summary,
                'credentialCategory' => 'certification',
                'identifier' => $model->credential_id,
                'url' => $model->credential_url,
                'recognizedBy' => self::pruneEmpty([
                    '@type' => 'Organization',
                    'name' => $model->issuer,
                    'url' => $model->credential_url,
                ]),
                'dateCreated' => $model->issued_on->toDateString(),
                'validThrough' => $model->expires_on?->toDateString(),
            ])),
            $model instanceof Publication => self::typedItem($position, self::pruneEmpty([
                '@type' => 'ScholarlyArticle',
                'name' => $model->title,
                'description' => $model->summary,
                'author' => $model->authors,
                'identifier' => $model->doi,
                'url' => $model->doi_url,
                'isPartOf' => self::pruneEmpty([
                    '@type' => 'Periodical',
                    'name' => $model->venue,
                ]),
                'datePublished' => $model->issued_on->toDateString(),
            ])),
            $model instanceof Project => self::typedItem($position, self::pruneEmpty([
                '@type' => 'SoftwareSourceCode',
                'name' => $model->title,
                'description' => $model->summary,
                'codeRepository' => $model->repo_url,
                'url' => $model->live_url ?? $model->repo_url,
                'dateCreated' => $model->started_on?->toDateString(),
            ])),
            $model instanceof Award => self::namedItem(
                $position,
                $model->title,
                trim($model->awarding_body.' — '.$model->awarded_at->year, ' —'),
            ),
            $model instanceof Writing => self::namedItem(
                $position,
                $model->title,
                $model->summary,
                self::url('writing.show', $model),
            ),
            $model instanceof WorkExperience => self::namedItem($position, $model->title, $model->summary),
            $model instanceof Education => self::namedItem($position, $model->title, $model->summary),
            default => throw new InvalidArgumentException(
                sprintf('[%s] has no structured-data item.', $model::class),
            ),
        };
    }

    /**
     * The `BlogPosting` member for `/writing/{slug}`.
     *
     * @return list<array<string, mixed>>
     */
    public static function blogPosting(Writing $writing): array
    {
        $url = self::url('writing.show', $writing);

        return [self::pruneEmpty([
            '@type' => 'BlogPosting',
            '@id' => $url.'#article',
            'mainEntityOfPage' => ['@id' => $url],
            'isPartOf' => ['@id' => self::siteUrl().'#website'],
            'headline' => $writing->title,
            'description' => $writing->meta_description ?? $writing->excerpt ?? $writing->summary,
            'url' => $url,
            'datePublished' => $writing->published_at?->toIso8601String(),
            'dateModified' => $writing->updated_at?->toIso8601String(),
            'author' => ['@id' => self::siteUrl().'#person'],
            'publisher' => ['@id' => self::siteUrl().'#person'],
            'image' => self::siteUrl().'/'.ltrim($writing->og_image_path ?? 'og/default.png', '/'),
            'inLanguage' => app()->getLocale(),
            'keywords' => $writing->tags->pluck('name')->all(),
        ])];
    }

    /**
     * A single `WebPage`-family member.
     *
     * `$type` is one of `ContactPage` (`/contact`), `SearchResultsPage`
     * (`/search`) or `WebPage` (`/privacy`) — the three §3.9 names.
     *
     * @return list<array<string, mixed>>
     */
    public static function page(string $type, string $url, string $name, ?string $description = null): array
    {
        return [self::pruneEmpty([
            '@type' => $type,
            '@id' => $url.'#webpage',
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'isPartOf' => ['@id' => self::siteUrl().'#website'],
        ])];
    }

    /**
     * The `BreadcrumbList` member, mirrored from the trail the Vue `Breadcrumbs`
     * component renders (§3.4).
     *
     * An empty trail — which is what `/` has, since it is the only page with no
     * breadcrumb — returns an empty list, so callers can always spread it.
     *
     * @param  list<array{title: string, href?: string|null}>  $trail
     * @return list<array<string, mixed>>
     */
    public static function breadcrumbs(array $trail): array
    {
        if ($trail === []) {
            return [];
        }

        $elements = [];
        $position = 1;

        foreach ($trail as $crumb) {
            $elements[] = self::pruneEmpty([
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $crumb['title'],
                'item' => $crumb['href'] ?? null,
            ]);
        }

        return [[
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ]];
    }

    /**
     * Build an absolute URL from `config('app.url')`, never the request host.
     *
     * §3.9 requires every canonical and every structured-data URL to come from
     * the configured origin, so the same page renders the same URLs behind a
     * proxy, in a queue worker and in a test.
     *
     * @param  array<string, mixed>|Model|string|int|null  $parameters
     */
    public static function url(string $routeName, mixed $parameters = []): string
    {
        return self::siteUrl().route($routeName, $parameters, false);
    }

    /**
     * The configured public origin, without a trailing slash.
     */
    private static function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * The owner's name, falling back to the application name.
     *
     * The fallback keeps the graph valid on a database that has not been seeded
     * yet — the `profile` row is seed data, not a boot requirement.
     */
    private static function personName(?Profile $profile): string
    {
        return $profile === null ? (string) config('app.name') : $profile->name;
    }

    /**
     * The institutions the owner studied at, deduplicated by name.
     *
     * @return list<array<string, mixed>>
     */
    private static function schools(): array
    {
        return array_values(Education::query()
            ->published()
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Education $education): array => self::pruneEmpty([
                '@type' => 'EducationalOrganization',
                'name' => $education->institution,
                'url' => $education->institution_url,
            ]))
            ->unique('name')
            ->values()
            ->all());
    }

    /**
     * The owner's certifications, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function credentials(): array
    {
        return array_values(Certification::query()
            ->published()
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Certification $certification): array => self::pruneEmpty([
                '@type' => 'EducationalOccupationalCredential',
                'name' => $certification->title,
                'credentialCategory' => 'certification',
                'identifier' => $certification->credential_id,
                'url' => $certification->credential_url,
                'recognizedBy' => self::pruneEmpty([
                    '@type' => 'Organization',
                    'name' => $certification->issuer,
                    'url' => $certification->credential_url,
                ]),
                'dateCreated' => $certification->issued_on->toDateString(),
                'validThrough' => $certification->expires_on?->toDateString(),
            ]))
            ->all());
    }

    /**
     * The tag names that describe what the owner works with.
     *
     * Ordered on the ASCII-normalised name, which is the only ordering rule the
     * tag model permits across both engines (§2.9 item 4).
     *
     * @return list<string>
     */
    private static function topics(): array
    {
        return array_values(Tag::query()
            ->orderByRaw('LOWER(name) ASC')
            ->orderBy('id')
            ->pluck('name')
            ->all());
    }

    /**
     * Wrap an already-typed node in its `ListItem`.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function typedItem(int $position, array $node): array
    {
        return [
            '@type' => 'ListItem',
            'position' => $position,
            'item' => $node,
        ];
    }

    /**
     * A `ListItem` that carries its own name rather than a typed `item`.
     *
     * @return array<string, mixed>
     */
    private static function namedItem(int $position, string $name, ?string $description, ?string $url = null): array
    {
        return self::pruneEmpty([
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $name,
            'description' => $description,
            'url' => $url,
        ]);
    }

    /**
     * Drop the keys that carry no information.
     *
     * A zero survives (an `ItemList` of nothing still reports `numberOfItems:
     * 0`); only `null`, the empty string and the empty array are removed, so a
     * nullable column never emits `"description": null`.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function pruneEmpty(array $node): array
    {
        return array_filter(
            $node,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
