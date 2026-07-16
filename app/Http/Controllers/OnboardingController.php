<?php

namespace App\Http\Controllers;

use App\Models\Family;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    // Once a user belongs to a family, onboarding is done: bounce them to the app instead of
    // letting them create a second family through the first-run flow.
    private function redirectIfOnboarded(Request $request): ?RedirectResponse
    {
        return $request->user()->families()->exists()
            ? to_route('dashboard')
            : null;
    }

    // "How would you like to begin?" — the first screen after registration.
    public function index(Request $request): RedirectResponse|Response
    {
        return $this->redirectIfOnboarded($request)
            ?? Inertia::render('onboarding/welcome', [
                'firstName' => str($request->user()->name)->before(' ')->toString(),
            ]);
    }

    // The "Create a family" form (name, description, color + emoji).
    public function create(Request $request): RedirectResponse|Response
    {
        return $this->redirectIfOnboarded($request)
            ?? Inertia::render('onboarding/create-family');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfOnboarded($request)) {
            return $redirect;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // Constrained to the palette/emoji sets the picker offers, so a crafted request can't
            // store arbitrary values that would later render as raw text in the UI.
            'color' => ['required', 'string', 'in:'.implode(',', self::COLORS)],
            'emoji' => ['required', 'string', 'in:'.implode(',', self::EMOJIS)],
        ]);

        $user = $request->user();

        $family = Family::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'],
            'emoji' => $data['emoji'],
            'owner_id' => $user->id,
        ]);
        $family->members()->attach($user, ['role' => 'admin']);

        // Make it the active tenant immediately (SetActiveFamily reads this on the next request).
        $request->session()->put('active_family_id', $family->id);

        return to_route('dashboard');
    }

    // Keep these in sync with the picker in resources/js/pages/onboarding/create-family.tsx.
    private const COLORS = ['#2D5E3A', '#D4943A', '#C2453A', '#4A6B52', '#1B3A28', '#7A9A80'];

    private const EMOJIS = ['🌿', '🏡', '☀️', '🐻', '🌸', '⭐'];
}
