<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\Publishable;
use Carbon\CarbonImmutable;
use Database\Factories\CertificationFactory;
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
 * @property string $issuer
 * @property string|null $credential_id
 * @property string|null $credential_url
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $expires_on
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
    'issuer',
    'credential_id',
    'credential_url',
    'issued_on',
    'expires_on',
])]
class Certification extends Model
{
    /** @use HasFactory<CertificationFactory> */
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
            'expires_on' => 'date',
        ];
    }
}
