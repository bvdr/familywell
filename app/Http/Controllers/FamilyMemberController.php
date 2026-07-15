<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;

class FamilyMemberController extends Controller
{
    public function store(Request $request)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', 'in:admin,member'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();
        $family->members()->syncWithoutDetaching([
            $user->id => ['role' => $data['role']],
        ]);

        return back();
    }

    public function destroy(User $user)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);
        $family->members()->detach($user->id);

        return back();
    }
}
