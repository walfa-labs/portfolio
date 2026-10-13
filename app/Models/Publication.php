<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\Publishable;
use Carbon\CarbonImmutable;
use Database\Factories\PublicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string|null $body
 * @property ContentStatus $status
 * @property CarbonImmutable|null $published_at
 * @property bool $is_placeholder
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property int $sort_order
 * @property string $venue
 * @property string|null $authors
 * @property string|null $doi
 * @property string|null $doi_url
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'title',
    'summary',
    'body',
    'status',
    'published_at',
    'is_placeholder',
    'meta_title',
    'meta_description',
    'sort_order',
    'venue',
    'authors',
    'doi',
    'issued_on',
])]
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory, HasMedia, HasSlug, HasTags, Publishable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'is_placeholder' => 'boolean',
            'sort_order' => 'integer',
            'issued_on' => 'date',
        ];
    }

    /**
     * Get the DOI resolver URL for the stored identifier.
     *
     * @return Attribute<?string, never>
     */
    protected function doiUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->doi === null
                ? null
                : "https://doi.org/{$this->doi}",
        );
    }
}
