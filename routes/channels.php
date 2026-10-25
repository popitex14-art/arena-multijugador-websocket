<?php

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('game-room.{code}', function (User $user, string $code) {
    $room = GameRoom::where('code', $code)->first();

    return $room !== null && $room->players()->whereKey($user->id)->exists();
});
