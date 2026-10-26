<?php

namespace App\Support\Modules;

use App\Models\Family;
use Illuminate\Support\Facades\DB;

class ModuleRegistry
{
    /** @var list<string>|null module ids marked "preview" in module.json, see enabledIds(). A singleton, so once per process. */
    private ?array $previewIds = null;

    public function __construct(protected string $modulesPath) {}

    public function discover(): array
    {
        $out = [];
        foreach (glob($this->modulesPath.'/*/module.json') as $file) {
            $manifest = ModuleManifest::fromPath(dirname($file));
            $out[$manifest->id] = $manifest;
        }

        return $out;
    }

    public function manifest(string $id): ?ModuleManifest
    {
        return $this->discover()[$id] ?? null;
    }

    public function isInstalled(string $id): bool
    {
        return DB::table('modules')->where('module_id', $id)->exists();
    }

    public function markInstalled(string $id, string $version): void
    {
        DB::table('modules')->updateOrInsert(['module_id' => $id], ['version' => $version]);
    }

    public function isEnabled(string $id, int $familyId): bool
    {
        return in_array($id, $this->enabledIds($familyId), true);
    }

    /**
     * Enabled module ids for a family, ONE query per family per request. Every caller (shell shared
     * props, ShareModules, EnsureModuleEnabled, module controllers/hooks) routes through isEnabled(),
     * which used to run its own EXISTS: the app shell alone fired ~27 of them on every Inertia visit
     * (measured on /dashboard), i.e. half the queries of a page load, each a round trip to the
     * managed Postgres in prod. The memo lives on the current Request's attribute bag so it dies with
     * the request under Octane and per test request, no flush wiring.
     * ponytail: in console the bound Request lives for the whole process, so a long-running worker
     * would keep a family's enabled-set until restart. Nothing queued calls isEnabled() today (only
     * HTTP + the scheduled check commands); if a job ever needs live state, clear the bag per job.
     *
     * @return list<string>
     */
    public function enabledIds(int $familyId): array
    {
        $bag = app('request')->attributes;
        $key = "modules.enabled.{$familyId}";
        if (! $bag->has($key)) {
            $bag->set($key, DB::table('family_modules')
                ->where(['family_id' => $familyId, 'enabled' => true])
                ->pluck('module_id')->all());
        }

        // A module still in preview ("preview": true in module.json) is off for everyone not opted in to it, so its
        // nav link, routes (EnsureModuleEnabled) and hooks all stay hidden through this one check. Read after the
        // memo, since the person can differ per request while the family's set doesn't.
        // ponytail: in console there's no person, so a preview module is off for scheduled commands too; fine while
        // it's a preview, give it a family-level switch if one ever needs jobs before release.
        // The list is read once per process: module.json files only change on deploy, which restarts it.
        $preview = $this->previewIds ??= array_keys(array_filter($this->discover(), fn (ModuleManifest $m) => $m->preview));

        $ids = $preview === [] ? $bag->get($key) : array_values(array_filter($bag->get($key), fn ($id) => ! in_array($id, $preview, true) || Preview::on($id)));

        // A teen sees their lists, tasks and calendar only (the onboarding's "What each role can do"). Filtered here,
        // per person like previews, so the nav, the module routes (EnsureModuleEnabled), hooks and MCP all agree.
        return $this->isTeen($familyId) ? array_values(array_intersect($ids, Family::TEEN_MODULES)) : $ids;
    }

    /** Is this request's person a teen in the family? One small query per family per request, memoized like above. */
    private function isTeen(int $familyId): bool
    {
        $userId = app('request')->user()?->id;
        if (! $userId) {
            return false;
        }
        $bag = app('request')->attributes;
        $key = "family.teen.{$familyId}.{$userId}";
        if (! $bag->has($key)) {
            $bag->set($key, DB::table('family_user')->where(['family_id' => $familyId, 'user_id' => $userId])->value('role') === 'teen');
        }

        return $bag->get($key);
    }

    /** Drop the per-request memo after a toggle so the same request sees the new state. */
    private function forgetEnabled(int $familyId): void
    {
        app('request')->attributes->remove("modules.enabled.{$familyId}");
    }

    public function enable(string $id, int $familyId): void
    {
        if (! $this->isInstalled($id)) {
            throw new \RuntimeException("Module [{$id}] is not installed.");
        }
        // Only a row flip. Never DDL (spec 12.2).
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => true],
        );
        $this->forgetEnabled($familyId);
    }

    /**
     * Turn every installed module on for a brand-new family (onboarding): new families start with the
     * whole app and switch off what they don't need. Existing families are never touched. One read + one
     * insert; a new family has no family_modules rows yet, so a plain insert is enough.
     */
    public function enableAll(int $familyId): void
    {
        $ids = DB::table('modules')->whereIn('module_id', array_keys($this->discover()))->pluck('module_id');
        DB::table('family_modules')->insert($ids->map(fn ($id) => ['family_id' => $familyId, 'module_id' => $id, 'enabled' => true])->all());
        $this->forgetEnabled($familyId);
    }

    public function disable(string $id, int $familyId): void
    {
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => false],
        );
        $this->forgetEnabled($familyId);
    }
}
