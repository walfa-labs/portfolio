<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\Publishable;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string|null $repo_url
 * @property string|null $live_url
 * @property CarbonImmutable|null $started_on
 * @property CarbonImmutable|null $ended_on
 * @property bool $is_featured
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
    'repo_url',
    'live_url',
    'started_on',
    'ended_on',
    'is_featured',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
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
            'started_on' => 'date',
            'ended_on' => 'date',
            'is_featured' => 'boolean',
        ];
    }
}
