<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Carbon\CarbonImmutable;
use Database\Factories\WritingSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['title', 'description'])]
class WritingSeries extends Model
{
    /** @use HasFactory<WritingSeriesFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    /**
     * Get the writings that appear in the series.
     *
     * @return HasMany<Writing, $this>
     */
    public function writings(): HasMany
    {
        return $this->hasMany(Writing::class, 'series_id');
    }
}
