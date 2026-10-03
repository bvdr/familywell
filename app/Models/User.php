<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    // HasApiTokens: Passport tokens issued to MCP clients through the OAuth consent flow (see docs/mcp.md).
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'notification_prefs',
        'locale',
        'theme',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_prefs' => 'array',
        ];
    }

    // Does this user want pushes for the given category? (missing/unset = yes). Categories themselves
    // live in the notifications module's registry; this only reads the saved on/off map.
    public function wantsPush(string $category): bool
    {
        return (bool) ($this->notification_prefs[$category] ?? true);
    }
}
