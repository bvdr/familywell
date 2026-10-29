<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    // HasApiTokens: Passport tokens issued to MCP clients through the OAuth consent flow (see docs/mcp.md).
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    // Every user gets a pseudonymous analytics id up front (the column is NOT NULL). Sign-up may then swap it
    // for the familywell.org visitor id they arrived with (RegisteredUserController::adoptAnalytics), so
    // `??=` leaves a preset one alone. Deliberately not fillable, like is_platform_admin.
    protected static function booted(): void
    {
        static::creating(fn (User $user) => $user->analytics_id ??= (string) Str::uuid());
    }

    /**
     * Member of the public demo family (is_demo): the account anyone can enter via /demo. Memoized per
     * request; BlockDemo asks it on the guarded account/family routes.
     */
    public function isDemo(): bool
    {
        return $this->isDemoCache ??= $this->families()->where('is_demo', true)->exists();
    }

    private ?bool $isDemoCache = null;

    /**
     * Someone just proved they own this address (an email code, or Google/Apple vouching for it). On an
     * account that never verified its address, whoever registered it before may not be that person: they may
     * have planted it under someone else's address. Hand the account over clean, so only the proven owner gets
     * in from now on: mark it verified, rotate the remember-me token, end every stored session, and remove
     * every other way back in, the linked Google/Apple sign-ins (SocialLogin checks those before the email),
     * MCP/OAuth tokens and push subscriptions. Already-verified accounts are left alone.
     *
     * Security audit 2026-10-19: the first version only did the session part, so a squatter's linked Google
     * account and Passport token still got them back into the account after the real owner claimed it.
     */
    public function confirmEmailOwner(): void
    {
        if ($this->hasVerifiedEmail()) {
            return;
        }
        $this->forceFill(['email_verified_at' => now(), 'remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
        }
        $this->socialAccounts()->delete();
        $tokenIds = Token::where('user_id', $this->id)->pluck('id');
        Token::whereKey($tokenIds)->update(['revoked' => true]);
        RefreshToken::whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        $this->pushSubscriptions()->delete();
    }

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    // "Sign in with Google / Apple" links (App\Support\Auth\SocialLogin).
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
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
        // Analytics stays out of every serialized user (auth.user, member lists): the id reaches the page
        // only as the `analyticsId` prop, and only where Umami is configured (HandleInertiaRequests).
        'analytics_id',
        'first_touch',
        // Reaches the page only as the `preview` prop (HandleInertiaRequests), not inside every serialized user.
        'preview_modules',
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
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'notification_prefs' => 'array',
            'first_touch' => 'array',
            // Module previews (App\Support\Modules\Preview); set only by module:preview, deliberately not fillable.
            'preview_modules' => 'array',
            // The modules pinned to the dashboard / sidebar (PinnedModulesController); null = never chose. Not fillable.
            'pinned_modules' => 'array',
            // Their own Home's section order + hidden ones (App\Support\Dashboard\HomeLayout); null = default. Not fillable.
            'home_layout' => 'array',
            // Set only by admin:grant / admin:revoke; deliberately not fillable.
            'is_platform_admin' => 'boolean',
        ];
    }

    // Does this user want pushes for the given category? (missing/unset = yes). Categories themselves
    // live in the notifications module's registry; this only reads the saved on/off map.
    public function wantsPush(string $category): bool
    {
        return (bool) ($this->notification_prefs[$category] ?? true);
    }
}
