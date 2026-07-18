<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// Per-entity visibility default: family | invited | private.
class ShareSetting extends Model
{
    public const VISIBILITIES = ['family', 'invited', 'private'];

    protected $fillable = ['shareable_type', 'shareable_id', 'visibility'];

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }
}
