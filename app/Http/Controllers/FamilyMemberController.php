<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
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

        // The family's name/colour/symbol editor and skin picker moved to Settings > Family > Name & look
        // (/settings/family/look), so this page only needs the name for its back pill and the owner for the badge.
        return Inertia::render('family/members', [
            'family' => ['name' => $family->name, 'owner_id' => $family->owner_id],
            'members' => $family->members->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role,
            ])->values(),
            // Pending invites are admin-only detail.
            'invitations' => $canManage
                // The family join link (email null) isn't a pending person, so it isn't listed.
                ? $family->invitations->whereNotNull('email')->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role])->values()
                : [],
            'canManage' => $canManage,
            // The same shareable join link onboarding's invite step shows, so an admin can invite without an email.
            // Admins only: anyone with it can join. link() renews it for 7 days on each view, as onboarding does.
            // Never in the demo: the demo family is public (BlockDemo), so a link there would let strangers' real
            // accounts join it.
            'link' => $canManage && ! auth()->user()->isDemo() ? FamilyInviteController::link($family, auth()->user()) : null,
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
