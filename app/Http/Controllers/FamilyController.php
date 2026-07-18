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
            // Any hex color and any single emoji, not just the presets. The emoji must start with a
            // pictographic character (so plain text is rejected) and stays short (one grapheme, incl.
            // ZWJ sequences like 👨‍👩‍👧‍👦).
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'emoji' => ['required', 'string', 'max:16', 'regex:/^\p{Extended_Pictographic}/u'],
        ]);

        $family->update($data);

        return back();
    }
}
