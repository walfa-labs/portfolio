<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $role_headline
 * @property string $positioning_sentence
 * @property string|null $location
 * @property string|null $availability
 * @property string|null $email
 * @property string|null $github_url
 * @property string|null $linkedin_url
 * @property string|null $orcid_url
 * @property CarbonImmutable|null $years_experience_start
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'role_headline',
    'positioning_sentence',
    'location',
    'availability',
    'email',
    'github_url',
    'linkedin_url',
    'orcid_url',
    'years_experience_start',
])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * The PRD specifies the singular `profile` table (§2.3), so the name must be
     * declared explicitly rather than left to the pluraliser.
     *
     * @var string
     */
    protected $table = 'profile';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'years_experience_start' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
