<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameEnemy extends Model
{
    protected $fillable = [
        'game_room_id',
        'x',
        'y',
        'health',
        'speed',
        'last_attack_at',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'game_room_id');
    }
}
