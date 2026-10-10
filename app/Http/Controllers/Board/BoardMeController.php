<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/board/me: who is signing in to the module-requests board. Deliberately minimal (no email
// address): the board keeps app ids and the display name people choose, nothing else. Only tokens that
// a board client obtained with `board:identity` may call it.
class BoardMeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->token();
        abort_unless($user->tokenCan('board:identity') && OAuthClient::isBoardClient((string) $token?->oauth_client_id), 403);

        return response()->json([
            'user_id' => $user->id,
            'email_verified' => $user->hasVerifiedEmail(),
            'families' => $user->families()->orderBy('name')->get(['families.id', 'families.name'])
                ->map(fn ($family) => ['id' => $family->id, 'name' => $family->name])->values(),
        ]);
    }
}
