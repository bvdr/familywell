<?php

namespace App\Http\Controllers;

use App\Support\Engagement;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;

/**
 * The Add sheet's "Most used · last 30 days" (design XMkKm, H2d Classic add): per enabled module, how many items
 * the family added in the last 30 days (the item = the model Engagement tracks). Family-wide on purpose, the
 * design ranks by what the family adds most; a count reveals nothing from anyone's private lists.
 * The design's "Last: bananas" line is left out: Classic's Shopping / To-do entries create a LIST (the module's
 * existing create sheet), so naming the last item under them would mislead. Item-level adds are "Just say it".
 *
 * Fetched when the sheet opens, not shared on every page.
 * ponytail: one COUNT per enabled module (~11); fold into one UNION if the sheet ever feels slow.
 */
class AddUsageController extends Controller
{
    public function __invoke(ModuleRegistry $modules): JsonResponse
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

        return response()->json($usage);
    }
}
