<?php

namespace App\Http\Controllers;

use App\Mail\FamilyInvitationMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Support\Analytics;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FamilyInviteController extends Controller
{
    // Admin invites someone by email; we email them an accept link.
    public function store(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:admin,member'],
        ]);

        if ($family->members()->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'That person is already in the family.']);
        }

        // One pending invite per email per family: reuse the row (and its token) if it already exists.
        $invite = $family->invitations()->updateOrCreate(
            ['email' => $data['email']],
            ['role' => $data['role'], 'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(7)],
        );

        Mail::to($invite->email)->send(new FamilyInvitationMail($invite));
        Analytics::track($request->user(), 'member_invited', [], '/family');

        return back();
    }

    public function destroy(Request $request, int $invitation): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);
        // Scope to the current family so an admin can't revoke another family's invite.
        $family->invitations()->findOrFail($invitation)->delete();

        return back();
    }

    // GET /invite/{token} — the accept screen. Guests are sent to register first.
    public function show(Request $request, string $token): RedirectResponse|Response
    {
        $invite = FamilyInvitation::with(['family', 'inviter'])->where('token', $token)->first();

        if (! $invite || $invite->isExpired()) {
            return redirect()->route('login')->with('status', 'That invitation link is invalid or has expired.');
        }

        if (! $request->user()) {
            // Remember the invite so registration/login can complete it, then send them to sign up.
            $request->session()->put('invite_token', $token);

            return redirect()->route('register');
        }

        if ($invite->family->members()->where('users.id', $request->user()->id)->exists()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('family/invite', [
            'invite' => [
                'token' => $invite->token,
                'family' => ['name' => $invite->family->name, 'emoji' => $invite->family->emoji, 'symbol' => $invite->family->symbol, 'color' => $invite->family->color],
                'inviter' => $invite->inviter->name,
                'memberCount' => $invite->family->members()->count(),
            ],
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invite = FamilyInvitation::where('token', $token)->first();

        if (! $invite || $invite->isExpired()) {
            return redirect()->route('dashboard')->with('status', 'That invitation is no longer valid.');
        }

        self::join($request, $invite);

        return redirect()->route('dashboard');
    }

    // Shared by accept() and post-registration: attach the user, consume the invite, make it active.
    public static function join(Request $request, FamilyInvitation $invite): void
    {
        $invite->family->members()->syncWithoutDetaching([
            $request->user()->id => ['role' => $invite->role],
        ]);
        $request->session()->put('active_family_id', $invite->family_id);
        $request->session()->forget('invite_token');
        $invite->delete();
        Analytics::track($request->user(), 'invite_accepted', [], '/invite');
    }
}
