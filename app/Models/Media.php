<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property string $id
 * @property string $mediable_type
 * @property int $mediable_id
 * @property string $collection
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property string|null $alt
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $sha256
 * @property array<int, array<string, mixed>>|null $variants
 * @property string $status
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'mediable_type',
    'mediable_id',
    'collection',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'alt',
    'size',
    'width',
    'height',
    'sha256',
    'variants',
    'status',
    'sort_order',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasUlids;

    /**
     * Bootstrap the model.
     */
    protected static function booted(): void
    {
        static::deleting(function (Media $media): void {
            $media->deleteObjectsFromDisk();
        });
    }

    /**
     * Get the owning mediable model.
     *
     * @return MorphTo<Model, $this>
     */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Remove the original and every variant object from the disk.
     *
     * Honours the shared-object rule: an object is only deleted once the last media
     * row referencing it is removed. Defensive — a storage failure never blocks the
     * row deletion.
     */
    protected function deleteObjectsFromDisk(): void
    {
        $diskName = $this->disk;

        if ($diskName === '') {
            return;
        }

        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable) {
            return;
        }

        $paths = [];

        if ($this->path !== '') {
            $paths[] = $this->path;
        }

        foreach (is_array($this->variants) ? $this->variants : [] as $variant) {
            if (isset($variant['path']) && is_string($variant['path']) && $variant['path'] !== '') {
                $paths[] = $variant['path'];
            }
        }

        foreach (array_unique($paths) as $path) {
            if ($this->hasOtherReferences($diskName, $path)) {
                continue;
            }

            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable) {
                // Never let a storage failure block the row deletion.
            }
        }
    }

    /**
     * Determine whether another media row on the same disk still references the
     * given object path, either as its original path or inside its variants.
     */
    protected function hasOtherReferences(string $diskName, string $path): bool
    {
        return static::query()
            ->where('disk', $diskName)
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query) use ($path): void {
                $query->where('path', $path)
                    ->orWhere('variants', 'like', '%'.$path.'%');
            })
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
