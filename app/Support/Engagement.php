<?php

namespace App\Support;

use App\Models\Family;
use App\Support\Billing\Billing;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which modules a family has actually used: the first time someone added an item in each. Drives the
 * paywall (Billing::hasAccess: unpaid families keep access until MODULES_NEEDED modules, and the grace
 * period) and the admin's per-family progress bar.
 *
 * A module joins with ONE line in its service provider, naming the model that counts as "adding an item":
 *   Engagement::track('shopping', ShoppingItem::class);
 * Containers and things the app creates by itself (lists, categories, settings) aren't tracked: only the
 * person's own items. Every creation path counts (web, assistant/MCP, guest links), since it hooks the
 * model's `created` event.
 */
final class Engagement
{
    public const MODULES_NEEDED = 3;

    /** @var array<string, class-string<Model>> module id => the model whose creation counts */
    private static array $tracked = [];

    /** @param class-string<Model> $model a family-scoped model (has family_id) */
    public static function track(string $module, string $model): void
    {
        self::$tracked[$module] = $model;
        $model::created(fn (Model $item) => self::record((int) $item->family_id, $module));
    }

    /**
     * One insert-or-ignore per created item (unique family+module), so it costs a single cheap query.
     * Only a NEW module row then counts the family's modules, and stamps engaged_at once it reaches the bar.
     * ponytail: one query per item creation; cache the known (family, module) pairs if that ever shows up.
     */
    public static function record(int $familyId, string $module, ?Carbon $at = null): void
    {
        if ($familyId <= 0) {
            return;
        }
        $at ??= now();
        $new = DB::table('family_module_activity')->insertOrIgnore(['family_id' => $familyId, 'module_id' => $module, 'created_at' => $at]);
        if ($new) {
            self::stampEngaged($familyId);
        }
    }

    /**
     * The admin's view of a family: the modules used (oldest first, with their names), how many are needed,
     * when the family reached it, and when the paywall shows (null = not yet decided / paid families).
     *
     * @param  list<object{module_id: string, created_at: string}>|null  $rows  preloaded rows (list pages)
     * @return array{modules: list<array{id: string, name: string, at: string}>, needed: int, engagedAt: ?string, paywallAt: ?string}
     */
    public static function summary(Family $family, ?iterable $rows = null): array
    {
        $rows ??= DB::table('family_module_activity')->where('family_id', $family->id)->orderBy('created_at')->get();
        $registry = app(ModuleRegistry::class);
        $modules = collect($rows)->sortBy('created_at')->map(fn ($r) => [
            'id' => $r->module_id,
            'name' => $registry->manifest($r->module_id)?->name ?? $r->module_id,
            'at' => Carbon::parse($r->created_at)->toIso8601String(),
        ])->values()->all();
        $paywallAt = $family->engaged_at ? max($family->engaged_at, $family->created_at->copy()->addHours(Billing::GRACE_HOURS)) : null;

        return [
            'modules' => $modules,
            'needed' => self::MODULES_NEEDED,
            'engagedAt' => $family->engaged_at?->toIso8601String(),
            'paywallAt' => $paywallAt?->toIso8601String(),
        ];
    }

    /**
     * Rebuild the activity from existing items (each tracked module's oldest item per family), then stamp
     * engaged_at where the bar is already met, dated when the deciding module was first used. Idempotent:
     * safe to re-run. Modules whose table doesn't exist yet (not migrated) are skipped.
     */
    public static function backfill(): void
    {
        foreach (self::$tracked as $module => $model) {
            $table = (new $model)->getTable();
            if (! Schema::hasTable($table)) {
                continue;
            }
            $firsts = DB::table($table)->whereNotNull('family_id')->groupBy('family_id')
                ->selectRaw('family_id, min(created_at) as created_at')->get();
            foreach ($firsts as $f) {
                DB::table('family_module_activity')->insertOrIgnore([
                    'family_id' => $f->family_id, 'module_id' => $module, 'created_at' => $f->created_at ?? now(),
                ]);
            }
        }
        foreach (DB::table('family_module_activity')->distinct()->pluck('family_id') as $familyId) {
            self::stampEngaged((int) $familyId);
        }
    }

    // engaged_at = when the family's Nth module was first used (N = MODULES_NEEDED). Set once, never moved.
    private static function stampEngaged(int $familyId): void
    {
        $nth = DB::table('family_module_activity')->where('family_id', $familyId)
            ->orderBy('created_at')->skip(self::MODULES_NEEDED - 1)->value('created_at');
        if ($nth) {
            DB::table('families')->where('id', $familyId)->whereNull('engaged_at')->update(['engaged_at' => $nth]);
        }
    }
}
