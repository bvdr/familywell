<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FamilyMemberController extends Controller
{
    public function index(): Response
    {
        $family = Family::with(['members', 'invitations'])->findOrFail(CurrentFamily::id());
        $canManage = Gate::allows('manageMembers', $family);

        return Inertia::render('family/members', [
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'description' => $family->description,
                'color' => $family->color,
                'emoji' => $family->emoji,
                'symbol' => $family->symbol,
                'owner_id' => $family->owner_id,
                'theme' => $family->theme ?: ThemeTokens::DEFAULT,
            ],
            'members' => $family->members->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role,
            ])->values(),
            // Pending invites are admin-only detail.
            'invitations' => $canManage
                // The family join link (email null) isn't a pending person, so it isn't listed.
                ? $family->invitations->whereNotNull('email')->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role])->values()
                : [],
            'canManage' => $canManage,
            'palette' => ['colors' => Family::COLORS, 'emojis' => Family::EMOJIS],
            'themes' => ThemeTokens::available(),
            // The skin CSS this person sees right now, so a family-default change applies in place
            // (useSkinCss) instead of on the next full page load.
            'themeCss' => ThemeTokens::css(ThemeTokens::resolve(auth()->user(), $family->id)),
        ]);
    }

    public function destroy(User $user): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        // The owner anchors the family; removing them is disallowed.
        abort_if($user->id === $family->owner_id, 403, 'The family owner cannot be removed.');
        $family->members()->detach($user->id);

        return back();
    }
}
