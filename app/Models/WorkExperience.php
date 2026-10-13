<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\Publishable;
use Carbon\CarbonImmutable;
use Database\Factories\WorkExperienceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string $employer
 * @property string|null $employer_url
 * @property string|null $location
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property array<int, string>|null $highlights
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
    'employer',
    'employer_url',
    'location',
    'start_date',
    'end_date',
    'highlights',
])]
class WorkExperience extends Model
{
    /** @use HasFactory<WorkExperienceFactory> */
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
            'start_date' => 'date',
            'end_date' => 'date',
            'highlights' => 'array',
        ];
    }

    /**
     * Order current roles first, then most recently ended, newest id breaking ties.
     *
     * @param  Builder<WorkExperience>  $query
     * @return Builder<WorkExperience>
     */
    public function scopeCurrentFirst(Builder $query): Builder
    {
        return $query->orderByRaw('(end_date IS NULL) DESC')
            ->orderByDesc('end_date')
            ->orderByDesc('id');
    }
}
