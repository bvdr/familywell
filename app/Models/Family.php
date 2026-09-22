<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    use HasFactory;

    // The avatar palette offered by the create/edit UI; validated server-side against these.
    public const COLORS = ['#2D5E3A', '#D4943A', '#C2453A', '#4A6B52', '#1B3A28', '#7A9A80'];

    public const EMOJIS = ['🌿', '🏡', '☀️', '🐻', '🌸', '⭐'];

    // `is_demo` is intentionally NOT fillable: only DemoSeeder sets it (via forceFill). It anchors the
    // nightly demo reset, so a request must never be able to flag a family as the demo.
    protected $fillable = ['name', 'description', 'color', 'emoji', 'owner_id', 'theme'];

    protected $casts = ['is_demo' => 'boolean'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FamilyInvitation::class);
    }
}
