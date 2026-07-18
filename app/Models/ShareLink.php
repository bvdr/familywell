<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

// A tokenized read-only link to a shareable entity. Anyone with the token can view (no login).
class ShareLink extends Model
{
    protected $fillable = ['shareable_type', 'shareable_id', 'token', 'role', 'expires_at', 'created_by'];

    protected $casts = ['expires_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (ShareLink $link) {
            $link->token ??= Str::random(48);
            $link->role ??= 'viewer';
        });
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
