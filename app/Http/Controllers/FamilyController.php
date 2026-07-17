<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    // Edit the active family's details (name, description, avatar). Admin only.
    public function update(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['required', 'in:'.implode(',', Family::COLORS)],
            'emoji' => ['required', 'in:'.implode(',', Family::EMOJIS)],
        ]);

        $family->update($data);

        return back();
    }
}
