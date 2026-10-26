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

    // Membership roles. admin: runs the family (members, modules, billing). member: everything else. teen: their
    // lists, tasks and calendar only (TEEN_MODULES, enforced by ModuleRegistry::enabledIds), never manages anything.
    // The onboarding calls admin "Adult" (it's who you'd invite as a co-parent) and teen "Teen".
    public const ROLES = ['admin', 'member', 'teen'];

    // What a teen sees: the three core modules plus the add-ons that only extend them (and notifications).
    public const TEEN_MODULES = ['shopping', 'tasks', 'calendar', 'notifications', 'important_todos', 'recurring_todos'];

    // The onboarding palette (design-app.pen "Onboarding · Flow"): sun, coral, green, blue, lilac, ink.
    public const ONBOARDING_COLORS = ['#FFDF00', '#FF5A3C', '#5BD38A', '#7FA8FF', '#C8A6FF', '#121100'];

    // A stored `symbol` is a lucide key or this. Not checked against lucide's list server-side: the client
    // only draws known keys (anything else falls back to the emoji), so a crafted value can't render.
    public const SYMBOL_RULE = 'regex:/^[a-z0-9-]{1,40}$/';

    // `is_demo` is intentionally NOT fillable: only DemoSeeder sets it (via forceFill). It anchors the
    // nightly demo reset, so a request must never be able to flag a family as the demo.
    protected $fillable = ['name', 'description', 'color', 'emoji', 'symbol', 'owner_id', 'theme'];

    protected $casts = ['is_demo' => 'boolean', 'subscription_period_end' => 'datetime', 'subscription_canceling' => 'boolean', 'engaged_at' => 'datetime'];

    // Comped access ("Lifetime (VIP)"): `access_override`, like is_demo, is NOT fillable. Only the platform
    // admin paths set it, through Billing::setLifetime().
    public const LIFETIME = 'lifetime';

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
