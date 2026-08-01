<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\PushAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Stores/removes a browser's Web Push subscription for the current user (one row per device/endpoint),
// and fires a test notification. The actual keys come from the browser's PushManager.subscribe().
class PushController extends Controller
{
    public function subscribe(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth']);

        return response()->noContent();
    }

    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);
        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }

    public function test(Request $request): Response
    {
        $request->user()->notify(new PushAlert('FamilyWell', 'Push notifications are on for this device 🎉'));

        return response()->noContent();
    }

    // Save which categories the user wants pushes for (account-level, applies to all their devices).
    public function prefs(Request $request): Response
    {
        $request->validate(['prefs' => ['required', 'array'], 'prefs.*' => ['boolean']]);
        $prefs = collect(User::PUSH_CATEGORIES)
            ->mapWithKeys(fn ($c) => [$c => (bool) ($request->input("prefs.$c", true))])
            ->all();
        $request->user()->update(['notification_prefs' => $prefs]);

        return response()->noContent();
    }
}
