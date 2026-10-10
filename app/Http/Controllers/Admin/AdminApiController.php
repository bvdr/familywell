<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Read-only platform data for admin.familywell.org: accounts and families, never what families store
// (no notes, lists, expenses...) and never secrets. Lists: 50 per page, newest first, literal search.
class AdminApiController extends Controller
{
    private const PAGE = 50;

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(), 'is_platform_admin' => (bool) $user->is_platform_admin,
            'families' => $user->families()->orderBy('name')->get(['families.id', 'families.name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->values(),
        ]);
    }

    public function stats(): JsonResponse
    {
        // The demo family and its members are seeded and reset nightly: not real usage.
        $real = fn () => User::query()->whereNotIn('id', fn ($q) => $q->select('family_user.user_id')->from('family_user')
            ->join('families', 'families.id', '=', 'family_user.family_id')->where('families.is_demo', true));

        return response()->json([
            'users' => $real()->count(),
            'verifiedUsers' => $real()->whereNotNull('email_verified_at')->count(),
            'families' => Family::query()->where('is_demo', false)->count(),
            'signups7d' => $real()->where('created_at', '>=', now()->subDays(7))->count(),
            'signups30d' => $real()->where('created_at', '>=', now()->subDays(30))->count(),
            // Same "paying" set as Billing::PAID (past_due keeps access while Stripe retries the card).
            'payingFamilies' => Family::query()->where('is_demo', false)->whereIn('subscription_status', ['active', 'trialing', 'past_due'])->count(),
            'pastDueFamilies' => Family::query()->where('is_demo', false)->where('subscription_status', 'past_due')->count(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $rows = self::search($this->userQuery(), self::q($request), ['users.name', 'users.email'])
            ->orderByDesc('users.created_at')->orderByDesc('users.id')
            ->offset((self::page($request) - 1) * self::PAGE)->limit(self::PAGE + 1)->get();

        return self::paged($rows, fn (User $u) => self::userItem($u));
    }

    public function user(int $id): JsonResponse
    {
        $user = $this->userQuery()->addSelect('users.locale', 'users.is_platform_admin')->find($id);
        if (! $user) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(self::userItem($user) + [
            'locale' => $user->locale,
            'isPlatformAdmin' => (bool) $user->is_platform_admin,
            'families' => $user->families()->orderBy('name')->get(['families.id', 'families.name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'role' => $f->pivot->role])->values(),
        ]);
    }

    public function families(Request $request): JsonResponse
    {
        $rows = self::search($this->familyQuery(), self::q($request), ['families.name'])
            ->orderByDesc('families.created_at')->orderByDesc('families.id')
            ->offset((self::page($request) - 1) * self::PAGE)->limit(self::PAGE + 1)->get();

        return self::paged($rows, fn (Family $f) => self::familyItem($f));
    }

    public function family(int $id): JsonResponse
    {
        $family = $this->familyQuery()->find($id);
        if (! $family) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(self::familyItem($family) + [
            'members' => $family->members()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role,
                    'joinedAt' => self::iso($m->pivot->created_at)])->values(),
        ]);
    }

    private function userQuery(): Builder
    {
        // Last activity = newest web session (database session driver); one correlated subquery per row.
        return User::query()
            ->select('users.id', 'users.name', 'users.email', 'users.email_verified_at', 'users.created_at')
            ->selectSub(DB::table('sessions')->selectRaw('max(last_activity)')->whereColumn('sessions.user_id', 'users.id'), 'last_active')
            ->withCount('families');
    }

    private function familyQuery(): Builder
    {
        return Family::query()
            ->leftJoin('users as owners', 'owners.id', '=', 'families.owner_id')
            ->select('families.id', 'families.name', 'families.created_at', 'families.is_demo', 'families.subscription_status', 'families.stripe_customer_id', 'owners.name as owner_name')
            ->withCount('members');
    }

    private static function userItem(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'verified' => $u->email_verified_at !== null,
            'createdAt' => self::iso($u->created_at),
            'lastActiveAt' => $u->last_active ? self::iso(Carbon::createFromTimestamp((int) $u->last_active)) : null,
            'familyCount' => (int) $u->families_count,
        ];
    }

    private static function familyItem(Family $f): array
    {
        return [
            'id' => $f->id, 'name' => $f->name, 'ownerName' => $f->owner_name, 'memberCount' => (int) $f->members_count,
            'createdAt' => self::iso($f->created_at), 'isDemo' => (bool) $f->is_demo,
            // The Stripe customer id isn't a secret (it only works with our Stripe key); the admin links to it.
            'subscriptionStatus' => $f->subscription_status, 'stripeCustomerId' => $f->stripe_customer_id,
        ];
    }

    // LIKE with % _ \ escaped, so the search box is literal; portable across SQLite (tests) and Postgres.
    private static function search(Builder $query, string $q, array $columns): Builder
    {
        if ($q === '') {
            return $query;
        }
        $like = '%'.addcslashes(mb_strtolower($q), '\\%_').'%';

        return $query->where(function (Builder $w) use ($columns, $like) {
            foreach ($columns as $column) {
                $w->orWhereRaw("lower({$column}) like ? escape '\\'", [$like]);
            }
        });
    }

    private static function q(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 80);
    }

    // Clamped so a silly page number can't become a huge OFFSET.
    private static function page(Request $request): int
    {
        return max(1, min(10000, (int) $request->query('page', 1)));
    }

    private static function paged($rows, callable $map): JsonResponse
    {
        return response()->json(['items' => $rows->take(self::PAGE)->map($map)->values(), 'hasMore' => $rows->count() > self::PAGE]);
    }

    private static function iso($date): ?string
    {
        return $date ? Carbon::parse($date)->utc()->toIso8601ZuluString('millisecond') : null;
    }
}
