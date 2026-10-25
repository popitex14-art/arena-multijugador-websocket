<?php

namespace App\Http\Controllers;

use App\Events\RoomGameAction;
use App\Events\RoomPlayerJoined;
use App\Models\GameRoom;
use App\Services\GameStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GameRoomController extends Controller
{
    public function store(GameStateService $gameState): RedirectResponse
    {
        $room = DB::transaction(function (): GameRoom {
            do {
                $code = Str::upper(Str::random(6));
            } while (GameRoom::where('code', $code)->exists());

            $room = GameRoom::create([
                'code' => $code,
                'owner_id' => auth()->id(),
            ]);

            $room->players()->attach(auth()->id());

            return $room;
        });

        $gameState->start($room);
        broadcast(new RoomPlayerJoined($room->code, auth()->id(), auth()->user()->name));

        return redirect()->route('rooms.show', $room);
    }

    public function join(string $code): View|RedirectResponse
    {
        $room = GameRoom::where('code', Str::upper($code))->firstOrFail();

        $room->load('owner:id,name');

        return view('room-invite', compact('room'));
    }

    public function enter(string $code): RedirectResponse
    {
        $room = GameRoom::where('code', Str::upper($code))->firstOrFail();
        $isMember = $room->players()->whereKey(auth()->id())->exists();
        $room->players()->syncWithoutDetaching([auth()->id()]);

        if (! $isMember) {
            broadcast(new RoomPlayerJoined($room->code, auth()->id(), auth()->user()->name));
        }

        return redirect()->route('rooms.show', $room);
    }

    public function show(GameRoom $room, GameStateService $gameState): View
    {
        abort_unless($room->players()->whereKey(auth()->id())->exists(), 403);

        $gameState->start($room);
        $room->load(['owner:id,name', 'players:id,name']);
        $state = $gameState->snapshot($room);

        return view('game-room', compact('room', 'state'));
    }

    public function restart(Request $request, GameRoom $room, GameStateService $gameState): RedirectResponse
    {
        abort_unless($room->owner_id === $request->user()->id, 403);

        $state = $gameState->restart($room);

        broadcast(new RoomGameAction(
            $room->code,
            $request->user()->id,
            $request->user()->name,
            ['type' => 'restart', 'state' => $state],
        ));

        return redirect()->route('rooms.show', $room);
    }
}
