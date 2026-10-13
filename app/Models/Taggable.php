<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $tag_id
 * @property int $taggable_id
 * @property string $taggable_type
 */
#[Fillable(['tag_id', 'taggable_type', 'taggable_id'])]
class Taggable extends Pivot
{
    /**
     * The table associated with the model.
     *
     * {@see AsPivot} would otherwise default to the singular `taggable`.
     *
     * @var string
     */
    protected $table = 'taggables';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * Get the owning taggable model.
     *
     * @return MorphTo<Model, $this>
     */
    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }
}
