<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Add\AddKinds;
use App\Support\Add\AddReader;
use App\Support\Ai\AiPoints;
use App\Support\Engagement;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Add sheet's "Most used · last 30 days" (design XMkKm, H2d Classic add): per enabled module, how many items
 * the family added in the last 30 days (the item = the model Engagement tracks). Family-wide on purpose, the
 * design ranks by what the family adds most; a count reveals nothing from anyone's private lists.
 * Also, per person: the pinned shortcut(s) (users.add_pins, kept on top) and the sub line's "last: bananas", the last
 * thing THIS person added in each module that they can still see (AddKinds::last, the module's own query). It names
 * the last ITEM even where the Classic row opens the module's list sheet (Shopping, To-do): it's a reminder of what
 * you add there, as the design shows it.
 *
 * Fetched when the sheet opens, not shared on every page.
 * ponytail: one COUNT per enabled module (~11); fold into one UNION if the sheet ever feels slow.
 */
class AddUsageController extends Controller
{
    // Design H2d pins exactly one shortcut ("pin one to keep it on top"); pinning another moves the pin. Stored as a
    // list so allowing more is this number only.
    public const MAX_PINS = 1;

    public function __invoke(Request $request, ModuleRegistry $modules): JsonResponse
    {
        $enabled = $modules->enabledIds((int) CurrentFamily::id());
        $since = now()->subDays(30);
        $usage = [];
        foreach (Engagement::tracked() as $module => $class) {
            if (! in_array($module, $enabled, true)) {
                continue;
            }
            $usage[$module] = $class::query()->where((new $class)->getCreatedAtColumn(), '>=', $since)->count();
        }

        // Whether "Just say it" is on (a reader is configured) and the family's points this month (null = unlimited).
        $family = Family::findOrFail(CurrentFamily::id());

        $user = $request->user();

        return response()->json(['usage' => $usage,
            // A pin to a module that was turned off since is dropped here, and comes back when it's on again.
            'pins' => array_values(array_intersect($user->add_pins ?? [], $enabled)),
            'last' => (object) AddKinds::lastAdded($user, $enabled), // {} not [] when empty, for the sheet
            'ai' => [
                'available' => AddReader::available(),
                'pointsLeft' => AiPoints::left($family),
                'limit' => AiPoints::limit($family),
            ]]);
    }

    /** Pin / unpin Classic shortcuts: the full list, as with the dashboard pins (PinnedModulesController). */
    public function pins(Request $request, ModuleRegistry $modules): JsonResponse
    {
        // Only modules that are on for this family and count adds (the Classic shortcuts are among them).
        $known = array_intersect(array_keys(Engagement::tracked()), $modules->enabledIds((int) CurrentFamily::id()));
        $data = $request->validate([
            'pins' => ['present', 'array', 'max:'.self::MAX_PINS],
            'pins.*' => ['string', 'distinct', 'in:'.implode(',', $known)],
        ]);
        $request->user()->forceFill(['add_pins' => array_values($data['pins'])])->save();

        return response()->json(['pins' => $data['pins']]);
    }
}
