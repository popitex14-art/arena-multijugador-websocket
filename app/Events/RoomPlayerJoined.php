<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class RoomPlayerJoined implements ShouldBroadcastNow
{
    public function __construct(
        public string $roomCode,
        public int $playerId,
        public string $playerName,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('game-room.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'RoomPlayerJoined';
    }

    public function broadcastWith(): array
    {
        return [
            'playerId' => $this->playerId,
            'playerName' => $this->playerName,
        ];
    }
}
