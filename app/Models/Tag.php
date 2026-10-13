<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use App\Support\ContentSlug;
use Carbon\CarbonImmutable;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_placeholder
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'description', 'is_placeholder'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, HasSlug;

    /**
     * Build a unique slug from the tag's name.
     *
     * Overrides the title-based derivation {@see HasSlug} performs for the content
     * models; tags have a `name` column rather than a `title`.
     */
    protected static function deriveSlug(self $model): string
    {
        $base = ContentSlug::canonicalize((string) $model->name);

        if ($base === '') {
            $base = 'untitled';
        }

        $slug = $base;
        $suffix = 1;

        while (static::slugIsTaken($model, $slug)) {
            $slug = ContentSlug::withSuffix(ContentSlug::truncateForSuffix($base, ++$suffix), $suffix);
        }

        return $slug;
    }

    /**
     * Get the pivot rows that attach this tag to other models.
     *
     * @return HasMany<Taggable, $this>
     */
    public function taggables(): HasMany
    {
        return $this->hasMany(Taggable::class);
    }

    /**
     * Get the route key for the model.
     *
     * Tags are addressed by their slug — `/tags/{tag:slug}` is canonical and
     * `/tags` is additive (§3.7) — so `route('tags.show', $tag)` must generate
     * `/tags/{slug}` rather than `/tags/{id}`.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_placeholder' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
