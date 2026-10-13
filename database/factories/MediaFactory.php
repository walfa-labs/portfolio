<?php

namespace Database\Factories;

use App\Enums\ContentType;
use App\Jobs\GenerateMediaVariants;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The row defaults to a project cover: the most common mediable and
     * collection pair. `mediable_type` uses the morph alias, never the class
     * name (§2.5).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sha256 = hash('sha256', fake()->uuid());
        $alias = ContentType::Project->value;

        return [
            'mediable_type' => $alias,
            'mediable_id' => Project::factory(),
            'collection' => 'cover',
            'disk' => 's3',
            'path' => "{$alias}s/{$sha256}/original.jpg",
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'alt' => fake()->sentence(4),
            'size' => fake()->numberBetween(50_000, 4_000_000),
            'width' => 1600,
            'height' => 900,
            'sha256' => $sha256,
            'variants' => null,
            'status' => 'pending',
            'sort_order' => 0,
        ];
    }

    /**
     * A row whose variant pipeline has finished.
     *
     * The variant map mirrors what {@see GenerateMediaVariants}
     * writes, so a `processed()` row serves through the media route the same
     * way a real upload does.
     */
    public function processed(): static
    {
        return $this->state(function (array $attributes): array {
            $collection = (string) ($attributes['collection'] ?? 'cover');
            $path = (string) ($attributes['path'] ?? '');

            $keys = (array) config("media.variants.{$collection}", []);

            if ($collection === 'cover' && ($attributes['mediable_type'] ?? null) === ContentType::Writing->value) {
                $keys = array_merge($keys, (array) config('media.variants.og_image', []));
            }

            return [
                'status' => 'processed',
                'variants' => $this->variantMap($keys, $collection, $path),
            ];
        });
    }

    /**
     * A row on the private disk — the CV.
     */
    public function private(): static
    {
        return $this->forCollection('cv');
    }

    /**
     * A row for a specific collection, with the shape that collection implies.
     *
     * `cv` is the only private collection, so it is the only one that lands on
     * the `s3-private` disk and the only one owned by a `User`.
     */
    public function forCollection(string $collection): static
    {
        return $this->state(function (array $attributes) use ($collection): array {
            $isPdf = in_array($collection, ['pdf', 'cv'], true);
            $isOwnerMedia = $collection === 'cv';
            $alias = $isOwnerMedia ? 'user' : ContentType::Project->value;
            $sha256 = (string) ($attributes['sha256'] ?? hash('sha256', $collection));

            [$extension, $mimeType] = match ($collection) {
                'logo' => ['svg', 'image/svg+xml'],
                'pdf', 'cv' => ['pdf', 'application/pdf'],
                default => ['jpg', 'image/jpeg'],
            };

            return [
                'collection' => $collection,
                'disk' => $isOwnerMedia ? 's3-private' : 's3',
                'path' => "{$alias}s/{$sha256}/original.{$extension}",
                'original_name' => "original.{$extension}",
                'mime_type' => $mimeType,
                'alt' => in_array($collection, ['logo', 'badge', 'avatar'], true)
                    ? null
                    : (string) ($attributes['alt'] ?? fake()->sentence(4)),
                'width' => $isPdf ? null : 1600,
                'height' => $isPdf ? null : 900,
                'variants' => null,
                'mediable_type' => $isOwnerMedia ? 'user' : ContentType::Project->value,
                'mediable_id' => $isOwnerMedia ? User::factory() : Project::factory(),
            ];
        });
    }

    /**
     * A deliberately decorative row: `''`, never `NULL` (§9.2).
     */
    public function decorative(): static
    {
        return $this->state(fn (array $attributes): array => ['alt' => '']);
    }

    /**
     * Build the `variant_key => metadata` map the job would write.
     *
     * @param  array<int, string>  $keys
     * @return array<string, array{path: string, width: int, height: int, mime: string, bytes: int}>
     */
    protected function variantMap(array $keys, string $collection, string $path): array
    {
        if ($keys === []) {
            return [];
        }

        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        $formats = $collection === 'logo' ? ['png', 'webp'] : ['avif', 'webp', 'jpg'];

        $variants = [];

        foreach ($keys as $key) {
            [$width, $height] = $this->dimensionsFor($key);

            foreach ($formats as $format) {
                $variants[$key.'.'.$format] = [
                    'path' => ($directory !== '' ? $directory.'/' : '').$key.'.'.$format,
                    'width' => $width,
                    'height' => $height,
                    'mime' => match ($format) {
                        'avif' => 'image/avif',
                        'webp' => 'image/webp',
                        'png' => 'image/png',
                        default => 'image/jpeg',
                    },
                    'bytes' => fake()->numberBetween(4_000, 250_000),
                ];
            }
        }

        $lqipWidth = match ($collection) {
            'cover', 'screenshot', 'inline' => 32,
            'avatar', 'badge' => 16,
            default => null,
        };

        if ($lqipWidth !== null) {
            $variants['lqip'] = [
                'path' => ($directory !== '' ? $directory.'/' : '').'lqip.webp',
                'width' => $lqipWidth,
                'height' => in_array($collection, ['avatar', 'badge'], true)
                    ? $lqipWidth
                    : (int) round($lqipWidth * 9 / 16),
                'mime' => 'image/webp',
                'bytes' => fake()->numberBetween(300, 3_000),
            ];
        }

        return $variants;
    }

    /**
     * Read the width and height a variant key encodes.
     *
     * @return array{0: int, 1: int}
     */
    protected function dimensionsFor(string $key): array
    {
        if (preg_match('/_(\d+)x(\d+)$/', $key, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('/_(\d+)w$/', $key, $matches) === 1) {
            $width = (int) $matches[1];

            return [$width, (int) round($width * 9 / 16)];
        }

        return [0, 0];
    }
}
