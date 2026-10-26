<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            // The onboarding symbol (lucide key / 'monogram'). Settings still edits the emoji: picking one
            // there sends symbol=null, so the emoji the person just chose is what shows.
            'symbol' => ['nullable', 'string', Family::SYMBOL_RULE],
        ]);

        $family->update($data);

        return back();
    }

    // The family's default skin (admin only). Kept separate from update() so it doesn't need the
    // name/emoji fields. Members override it for themselves in Appearance settings.
    public function updateTheme(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $ids = collect(ThemeTokens::available())->pluck('id')->all();
        $data = $request->validate(['theme' => ['required', Rule::in($ids)]]);
        $family->update(['theme' => $data['theme']]);

        return back();
    }
}
