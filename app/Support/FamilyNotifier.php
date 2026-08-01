<?php

namespace App\Support;

use App\Models\Family;
use App\Models\User;
use App\Notifications\PushAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

// Sends a push to everyone who can see a shareable entity (or the whole family), respecting each
// recipient's per-category preference and never notifying the actor for their own action.
class FamilyNotifier
{
    /**
     * Users who can view a shareable entity: everyone in the family for `family` visibility, otherwise
     * just the users with an explicit share row (the owner has one too).
     *
     * @return Collection<int, User>
     */
    public static function accessibleUsers(Model $entity): Collection
    {
        $familyId = method_exists($entity, 'sharingFamilyId') ? $entity->sharingFamilyId() : ($entity->family_id ?? null);
        if (! $familyId) {
            return collect();
        }
        if (method_exists($entity, 'visibility') && $entity->visibility() === 'family') {
            return Family::find($familyId)?->members ?? collect();
        }

        return User::whereIn('id', $entity->shares->pluck('user_id'))->get();
    }

    /**
     * Push to the given users, minus the actor and anyone who muted this category.
     *
     * @param  iterable<User>  $users
     */
    public static function push(iterable $users, string $category, ?int $exceptUserId, string $title, string $body, string $url): void
    {
        $recipients = collect($users)
            ->filter(fn (User $u) => (int) $u->id !== (int) $exceptUserId && $u->wantsPush($category))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }
        // A push failure (bad VAPID, unreachable push service, expired sub) must never break the user's
        // action — notifications are best-effort. Swallow and log.
        try {
            Notification::send($recipients, new PushAlert($title, $body, $url));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // Convenience: push to everyone who can access $entity.
    public static function toAccessOf(Model $entity, string $category, ?int $exceptUserId, string $title, string $body, string $url): void
    {
        self::push(self::accessibleUsers($entity), $category, $exceptUserId, $title, $body, $url);
    }
}
