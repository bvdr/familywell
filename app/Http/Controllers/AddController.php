<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Add\AddContext;
use App\Support\Add\AddKinds;
use App\Support\Add\AddReader;
use App\Support\Ai\AiPoints;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * "Just say it" in the Add sheet (design XMkKm H2): read() turns what the person typed / pasted / photographed into
 * cards (AddReader), save() creates the ones they confirmed through each module's own rules (AddKinds). Reading is
 * free but needs points left (it runs while they type); a save that adds something costs one point (AiPoints).
 */
class AddController extends Controller
{
    public function read(Request $request): JsonResponse
    {
        abort_unless(AddReader::available(), 404);
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'images' => ['nullable', 'array', 'max:4'],
            // data: URLs from the browser, already downscaled there; ~4 MiB each is Clairic's cap.
            'images.*' => ['string', 'max:5600000', 'regex:#^data:image/(png|jpeg|webp);base64,#'],
            'path' => ['nullable', 'string', 'max:255'],
        ]);
        $family = Family::findOrFail(CurrentFamily::id());
        if (! AiPoints::canSpend($family)) {
            return response()->json(['outOfPoints' => true, 'pointsLeft' => 0], 409);
        }

        try {
            $items = AddReader::read($data['text'] ?? '', $data['images'] ?? [], AddContext::for($request->user(), $data['path'] ?? '/dashboard'));
        } catch (RuntimeException) {
            return response()->json(['message' => 'unavailable'], 503);
        }

        return response()->json(['items' => $items, 'pointsLeft' => AiPoints::left($family)]);
    }

    /** Voice: the recording so far -> words (free like reading, but it needs points left). */
    public function transcribe(Request $request): JsonResponse
    {
        abort_unless(AddReader::available(), 404);
        // ~10 MB is minutes of Opus / AAC; Chrome records audio/webm, Safari audio/mp4.
        $request->validate(['audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/webm,video/webm,audio/mp4,video/mp4,audio/ogg,audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/x-m4a']]);
        $family = Family::findOrFail(CurrentFamily::id());
        if (! AiPoints::canSpend($family)) {
            return response()->json(['outOfPoints' => true, 'pointsLeft' => 0], 409);
        }
        try {
            $text = AddReader::transcribe($request->file('audio'), AddContext::for($request->user(), (string) $request->input('path', '/dashboard')));
        } catch (RuntimeException) {
            return response()->json(['message' => 'unavailable'], 503);
        }

        return response()->json(['text' => $text]);
    }

    public function save(Request $request): JsonResponse
    {
        abort_unless(AddReader::available(), 404);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.kind' => ['required', 'string'],
            'items.*.values' => ['required', 'array'],
            'path' => ['nullable', 'string', 'max:255'],
        ]);
        $family = Family::findOrFail(CurrentFamily::id());
        if (! AiPoints::canSpend($family)) {
            return response()->json(['outOfPoints' => true, 'pointsLeft' => 0], 409);
        }
        $ctx = AddContext::for($request->user(), $data['path'] ?? '/dashboard');

        // All or nothing: a card that doesn't hold up (a bad date) saves none, and its message names the kind.
        $saved = DB::transaction(fn () => array_map(function (array $item) use ($ctx) {
            $kind = AddKinds::get($item['kind']) ?? throw ValidationException::withMessages(['items' => "Unknown kind {$item['kind']}."]);

            return ['kind' => $item['kind']] + ($kind['save'])($ctx->user, $item['values'], $ctx);
        }, $data['items']));
        AiPoints::charge('add_saved');

        return response()->json(['saved' => $saved, 'pointsLeft' => AiPoints::left($family)]);
    }
}
