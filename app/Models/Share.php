<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// An explicit per-user grant on a shareable entity. role = owner | editor | viewer.
class Share extends Model
{
    public const ROLES = ['owner', 'editor', 'viewer'];

    protected $fillable = ['shareable_type', 'shareable_id', 'user_id', 'role', 'invited_by'];

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
