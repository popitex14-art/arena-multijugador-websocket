<?php

namespace App\Http\Controllers;

use App\Events\RoomGameAction;
use App\Models\GameRoom;
use App\Services\GameStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GameActionController extends Controller
{
    public function __invoke(Request $request, GameRoom $room, GameStateService $gameState): JsonResponse
    {
        abort_unless($room->players()->whereKey($request->user()->id)->exists(), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['move', 'fire', 'weapon', 'heartbeat'])],
            'dx' => ['exclude_unless:type,move', 'required', 'numeric', 'between:-1,1'],
            'dy' => ['exclude_unless:type,move', 'required', 'numeric', 'between:-1,1'],
            'angle' => ['exclude_unless:type,fire', 'required', 'numeric', 'between:-3.141593,3.141593'],
            'weapon' => ['exclude_unless:type,weapon', 'required', 'integer', 'between:1,3'],
        ]);

        $action = $gameState->apply($room, $request->user(), $data);

        broadcast(new RoomGameAction(
            $room->code,
            $request->user()->id,
            $request->user()->name,
            $action,
        ));

        return response()->json(['action' => $action], 202);
    }
}
