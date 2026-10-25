<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GameRoom extends Model
{
    protected $fillable = ['code', 'owner_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'room_user', 'game_room_id', 'user_id')
            ->withPivot(['x', 'y', 'weapon', 'last_action_at', 'last_shot_at'])
            ->withTimestamps();
    }
}
