<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FamilyInvitation extends Model
{
    protected $fillable = ['family_id', 'email', 'role', 'token', 'invited_by', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (FamilyInvitation $invite) {
            $invite->token ??= Str::random(48);
            $invite->expires_at ??= now()->addDays(7);
        });
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
