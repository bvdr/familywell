<?php

namespace App\Support\Sharing;

use App\Models\Share;
use App\Models\ShareLink;
use App\Models\ShareSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

// Opt-in sharing for any model. Adds the polymorphic relations plus visibility/role helpers.
// The host model is expected to have a `family_id` (used to resolve the `family` visibility default);
// override sharingFamilyId() if it lives elsewhere.
trait Shareable
{
    public function shares(): MorphMany
    {
        return $this->morphMany(Share::class, 'shareable');
    }

    public function shareLinks(): MorphMany
    {
        return $this->morphMany(ShareLink::class, 'shareable');
    }

    public function shareSetting(): MorphOne
    {
        return $this->morphOne(ShareSetting::class, 'shareable');
    }

    /**
     * Rows $userId can see, in SQL: `family` visibility (no setting row, or one saying family) or an explicit
     * share row. Same rule as the `->filter(visibility() === 'family' || shares->contains(...))` the module
     * index pages run in PHP, but usable before pagination (the MCP list tools page in the database).
     * The family itself is already narrowed by the BelongsToFamily scope.
     */
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('shareSetting', fn (Builder $s) => $s->where('visibility', '!=', 'family'))
            ->orWhereHas('shares', fn (Builder $s) => $s->where('user_id', $userId)));
    }

    public function visibility(): string
    {
        return $this->shareSetting?->visibility ?? 'family';
    }

    public function setVisibility(string $visibility): void
    {
        abort_unless(in_array($visibility, ShareSetting::VISIBILITIES, true), 422);
        $this->shareSetting()->updateOrCreate([], ['visibility' => $visibility]);
        $this->unsetRelation('shareSetting');
    }

    // The family whose members get the `family` visibility default. Override if not `family_id`.
    public function sharingFamilyId(): ?int
    {
        return $this->family_id ?? null;
    }

    public function roleFor(?User $user): ?string
    {
        return app(ShareAccess::class)->roleFor($user, $this);
    }
}
