<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Billing\Billing;
use App\Support\Modules\ModuleRegistry;
use App\Support\Region;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Token;
use Modules\Notifications\NotificationCategories;

/**
 * The two settings hubs (design boards 12 "Me" and 11 "You & family"): Me is what the avatar opens, only what is
 * the person's own; Family settings holds the family-wide pages. Each row shows its current value on the right
 * ("English", "8 of 9 on", "4 people") so most questions are answered without opening the page.
 */
class SettingsHubController extends Controller
{
    public function me(Request $request): Response
    {
        $user = $request->user();
        $family = Family::find(CurrentFamily::id());
        $saved = $user->notification_prefs ?? [];
        $categories = array_keys(NotificationCategories::all());

        return Inertia::render('settings/index', [
            // Google / Apple when linked, else the email code everyone has.
            'signIn' => $user->socialAccounts()->pluck('provider')->unique()->values(),
            'localeName' => Translations::LOCALES[$user->locale ?? config('app.locale')] ?? null,
            'notifications' => ['on' => count(array_filter($categories, fn ($id) => (bool) ($saved[$id] ?? true))), 'total' => count($categories)],
            // Same "live" rule as Connected apps: not revoked, and valid or renewable.
            'apps' => Token::query()->where('user_id', $user->id)->where('revoked', false)
                ->where(fn ($q) => $q->where('expires_at', '>', now())
                    ->orWhereHas('refreshToken', fn ($r) => $r->where('revoked', false)->where('expires_at', '>', now())))
                ->with('client:id,name')->get()->pluck('client.name')->filter()->unique()->values(),
            'family' => $family?->only(['name', 'color', 'symbol']),
        ]);
    }

    public function family(Request $request, ModuleRegistry $registry): Response
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $installed = array_filter($registry->discover(), fn ($m) => ! $m->preview && $registry->isInstalled($m->id));

        return Inertia::render('settings/family', [
            'family' => $family->only(['name', 'color', 'symbol']),
            'isAdmin' => $request->user()->can('manageMembers', $family), // admin of this family
            'members' => $family->members()->count(),
            'modules' => ['on' => count(array_intersect(array_map(fn ($m) => $m->id, $installed), $registry->enabledIds($family->id))), 'total' => count($installed)],
            // lifetime / paid / free; null when billing is off (self-hosted): the row is hidden.
            'plan' => Billing::enabled() ? (Billing::isLifetime($family) ? 'lifetime' : (Billing::isPaid($family) ? 'paid' : 'free')) : null,
            // The "Region & units" row's value ("Metric · RON"): only the keys the installed modules report.
            'region' => Region::current($family->id),
        ]);
    }

    /**
     * Settings › Region & units (design Y7): the family hub with the shared Region & units sheet open on top, so
     * closing it lands back on the hub and the row's URL still works as a link. The sheet's extra data (~250
     * countries, the preview's real values) only loads here, not on every hub visit.
     */
    public function region(Request $request, ModuleRegistry $registry): Response
    {
        return $this->family($request, $registry)->with([
            'regionSheet' => [
                ...Region::current(CurrentFamily::id(), $request->user()->id),
                'countries' => Region::countries(),
            ],
        ]);
    }

    // Admin only (like each module's own settings); every member can open the sheet read-only. The modules
    // validate and store their own keys (Region::save); what's left unknown is ignored.
    public function updateRegion(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);
        Region::save($family, $request->all());

        return to_route('settings.family');
    }
}
