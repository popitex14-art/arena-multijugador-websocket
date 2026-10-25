<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class GlobalChatMessage implements ShouldBroadcastNow
{
    public function __construct(
        public string $playerName,
        public string $message,
        public string $sentAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('global-chat')];
    }

    public function broadcastAs(): string
    {
        return 'GlobalChatMessage';
    }
}
