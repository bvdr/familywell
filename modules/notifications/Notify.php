<?php

namespace Modules\Notifications;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

// The notifications hook other modules call. A single entry point that both logs the event in-app
// (for the activity feed) and sends a Web Push, respecting each recipient's per-category mute and
// never notifying the actor about their own action. Feature modules call these instead of touching
// the push/notification internals directly.
class Notify
{
    /**
     * Users who can view a shareable entity: everyone in the family for `family` visibility, otherwise
     * just the users with an explicit share row (the owner has one too). This is what makes the feed
     * access-filtered, private items only reach people who can see them.
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
     * Notify the given users, minus the actor. Every recipient gets an in-app log row; a Web Push
     * also goes out to those who haven't muted the category (decided in PushAlert::via).
     *
     * @param  iterable<User>  $users
     */
    public static function push(iterable $users, string $category, ?int $exceptUserId, string $title, string $body, string $url): void
    {
        $recipients = collect($users)
            ->filter(fn (User $u) => (int) $u->id !== (int) $exceptUserId)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }
        // Delivery is best-effort: a push failure (bad VAPID, expired sub) must never break the user's
        // action. The in-app log write is part of the same send, so we still try/catch around it.
        try {
            Notification::send($recipients, new PushAlert($title, $body, $url, $category));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // Convenience: notify everyone who can access $entity.
    public static function toAccessOf(Model $entity, string $category, ?int $exceptUserId, string $title, string $body, string $url): void
    {
        self::push(self::accessibleUsers($entity), $category, $exceptUserId, $title, $body, $url);
    }
}
