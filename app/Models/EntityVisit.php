<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// A user's "last opened" marker for a shareable entity. See the entity_visits migration.
class EntityVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'visitable_type', 'visitable_id', 'visited_at'];

    protected $casts = ['visited_at' => 'datetime'];

    // Record (or refresh) the current user's visit to an entity. Idempotent per (user, entity).
    // Named record() not touch() because Eloquent already defines an instance touch().
    public static function record(string $type, int $id): void
    {
        $userId = auth()->id();
        if (! $userId) {
            return;
        }
        static::updateOrCreate(
            ['user_id' => $userId, 'visitable_type' => $type, 'visitable_id' => $id],
            ['visited_at' => now()],
        );
    }

    /**
     * Map of visitable_id => visited_at (Carbon) for the current user, for one type, within the
     * recency window. Empty when signed out or nothing was opened recently.
     *
     * @return Collection<int, Carbon>
     */
    public static function recentMap(string $type, int $days = 3): Collection
    {
        $userId = auth()->id();
        if (! $userId) {
            return collect();
        }

        return static::query()
            ->where('user_id', $userId)
            ->where('visitable_type', $type)
            ->where('visited_at', '>=', now()->subDays($days))
            ->pluck('visited_at', 'visitable_id');
    }
}
