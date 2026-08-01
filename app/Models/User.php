<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

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

    // Push notification categories the user can toggle (Settings > Notifications). All default on.
    public const PUSH_CATEGORIES = ['tasks', 'shopping', 'spending', 'projects', 'sharing'];

    /** Does this user want pushes for the given category? (missing/unset = yes). */
    public function wantsPush(string $category): bool
    {
        return (bool) ($this->notification_prefs[$category] ?? true);
    }

    /** The full {category => bool} preference map, with defaults filled in. @return array<string,bool> */
    public function pushPrefs(): array
    {
        $saved = $this->notification_prefs ?? [];

        return collect(self::PUSH_CATEGORIES)->mapWithKeys(fn ($c) => [$c => (bool) ($saved[$c] ?? true)])->all();
    }
}
