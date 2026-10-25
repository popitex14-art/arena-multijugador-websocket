<?php

namespace App\Services;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class GameStateService
{
    private const WORLD_WIDTH = 960;

    private const WORLD_HEIGHT = 540;

    private const PLAYER_RADIUS = 18;

    private const ENEMY_RADIUS = 13;

    private const MOVE_SPEED = 220;

    private const WEAPONS = [
        1 => ['cooldown' => 0.22, 'speed' => 620, 'damage' => 25, 'pellets' => 1, 'spread' => 0.0],
        2 => ['cooldown' => 0.75, 'speed' => 450, 'damage' => 14, 'pellets' => 5, 'spread' => 0.48],
        3 => ['cooldown' => 0.10, 'speed' => 760, 'damage' => 15, 'pellets' => 1, 'spread' => 0.0],
    ];

    private const BARRIERS = [
        ['x' => 280, 'y' => 140, 'width' => 110, 'height' => 22],
        ['x' => 570, 'y' => 370, 'width' => 110, 'height' => 22],
        ['x' => 660, 'y' => 145, 'width' => 28, 'height' => 95],
        ['x' => 270, 'y' => 320, 'width' => 28, 'height' => 95],
    ];

    public function start(GameRoom $room): void
    {
        DB::transaction(function () use ($room): void {
            $now = microtime(true);
            $roomState = DB::table('game_rooms')
                ->where('id', $room->id)
                ->lockForUpdate()
                ->first();

            if ($roomState === null || $roomState->status !== 'active') {
                return;
            }

            if (! DB::table('game_enemies')->where('game_room_id', $room->id)->exists()) {
                $wave = max(1, (int) $roomState->wave);
                $this->spawnWave($room->id, $wave);
                $roomState->wave = $wave;
            }

            DB::table('game_rooms')
                ->where('id', $room->id)
                ->update([
                    'wave' => $roomState->wave,
                    'last_simulated_at' => $roomState->last_simulated_at ?? $now,
                ]);
        });
    }

    public function restart(GameRoom $room): array
    {
        return DB::transaction(function () use ($room): array {
            $roomState = DB::table('game_rooms')
                ->where('id', $room->id)
                ->lockForUpdate()
                ->first();

            abort_if($roomState === null, 404);
            abort_unless($roomState->status === 'finished', 409, 'La partida todavía está en curso.');

            DB::table('game_enemies')->where('game_room_id', $room->id)->delete();
            DB::table('game_projectiles')->where('game_room_id', $room->id)->delete();
            DB::table('room_user')
                ->where('game_room_id', $room->id)
                ->update([
                    'x' => 480,
                    'y' => 270,
                    'weapon' => 1,
                    'health' => 100,
                    'score' => 0,
                    'last_action_at' => null,
                    'last_shot_at' => null,
                ]);

            $now = microtime(true);

            DB::table('game_rooms')
                ->where('id', $room->id)
                ->update([
                    'wave' => 1,
                    'status' => 'active',
                    'last_simulated_at' => $now,
                ]);

            $this->spawnWave($room->id, 1);

            $room->setAttribute('wave', 1);
            $room->setAttribute('status', 'active');

            return $this->snapshot($room);
        });
    }

    public function snapshot(GameRoom $room): array
    {
        $players = DB::table('room_user')
            ->join('users', 'users.id', '=', 'room_user.user_id')
            ->where('room_user.game_room_id', $room->id)
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.name',
                'room_user.x',
                'room_user.y',
                'room_user.weapon',
                'room_user.health',
                'room_user.score',
            ]);

        return [
            'wave' => (int) $room->wave,
            'status' => $room->status,
            'players' => $players->map(fn (object $player): array => [
                'id' => (int) $player->id,
                'name' => $player->name,
                'x' => (float) $player->x,
                'y' => (float) $player->y,
                'weapon' => (int) $player->weapon,
                'health' => (int) $player->health,
                'score' => (int) $player->score,
            ])->all(),
            'enemies' => DB::table('game_enemies')
                ->where('game_room_id', $room->id)
                ->orderBy('id')
                ->get(['id', 'x', 'y', 'health', 'max_health', 'speed'])
                ->map(fn (object $enemy): array => [
                    'id' => (int) $enemy->id,
                    'x' => (float) $enemy->x,
                    'y' => (float) $enemy->y,
                    'health' => (int) $enemy->health,
                    'maxHealth' => (int) $enemy->max_health,
                    'speed' => (float) $enemy->speed,
                ])->all(),
            'projectiles' => DB::table('game_projectiles')
                ->where('game_room_id', $room->id)
                ->orderBy('spawned_at')
                ->get(['id', 'user_id', 'x', 'y', 'angle', 'speed', 'damage'])
                ->map(fn (object $projectile): array => [
                    'id' => $projectile->id,
                    'ownerId' => (int) $projectile->user_id,
                    'x' => (float) $projectile->x,
                    'y' => (float) $projectile->y,
                    'angle' => (float) $projectile->angle,
                    'speed' => (int) $projectile->speed,
                    'damage' => (int) $projectile->damage,
                ])->all(),
        ];
    }

    public function apply(GameRoom $room, User $player, array $input, ?float $now = null): array
    {
        return DB::transaction(function () use ($room, $player, $input, $now): array {
            $now ??= microtime(true);
            $roomState = DB::table('game_rooms')
                ->where('id', $room->id)
                ->lockForUpdate()
                ->first();
            $playerState = DB::table('room_user')
                ->where('game_room_id', $room->id)
                ->where('user_id', $player->id)
                ->lockForUpdate()
                ->first();

            abort_if($roomState === null || $playerState === null, 403);

            if ($roomState->status !== 'active' || (int) $playerState->health === 0) {
                abort(409, 'La partida terminó o tu jugador ya fue eliminado.');
            }

            $action = match ($input['type']) {
                'move' => $this->move($room->id, $player->id, $playerState, $input, $now),
                'weapon' => $this->changeWeapon($room->id, $player->id, $input),
                'fire' => $this->fire($room->id, $player->id, $playerState, $input, $now),
                'heartbeat' => ['type' => 'heartbeat'],
            };

            $delta = $roomState->last_simulated_at === null
                ? 0.1
                : min(max($now - (float) $roomState->last_simulated_at, 0), 0.1);

            $roomState->last_simulated_at = $now;
            $this->advanceEnemies($room->id, $delta, $now);
            $this->advanceProjectiles($room->id, $delta, $now);

            if ($this->finishGameIfAllPlayersAreDead($room->id)) {
                $roomState->status = 'finished';
            } else {
                $this->finishWaveIfClear($room->id, $roomState);
            }

            DB::table('game_rooms')
                ->where('id', $room->id)
                ->update([
                    'wave' => $roomState->wave,
                    'status' => $roomState->status,
                    'last_simulated_at' => $now,
                ]);

            $room->setAttribute('wave', $roomState->wave);
            $room->setAttribute('status', $roomState->status);
            $action['state'] = $this->snapshot($room);

            return $action;
        });
    }

    private function move(int $roomId, int $playerId, object $state, array $input, float $now): array
    {
        $dx = (float) $input['dx'];
        $dy = (float) $input['dy'];
        $length = hypot($dx, $dy);

        if ($length > 1) {
            $dx /= $length;
            $dy /= $length;
        }

        $elapsed = $state->last_action_at === null
            ? 0.1
            : min(max($now - (float) $state->last_action_at, 0), 0.1);
        $x = (float) $state->x;
        $y = (float) $state->y;
        $nextX = min(max($x + $dx * self::MOVE_SPEED * $elapsed, self::PLAYER_RADIUS), self::WORLD_WIDTH - self::PLAYER_RADIUS);
        $nextY = min(max($y + $dy * self::MOVE_SPEED * $elapsed, self::PLAYER_RADIUS), self::WORLD_HEIGHT - self::PLAYER_RADIUS);

        if (! $this->hitsBarrier($nextX, $y, self::PLAYER_RADIUS)) {
            $x = $nextX;
        }

        if (! $this->hitsBarrier($x, $nextY, self::PLAYER_RADIUS)) {
            $y = $nextY;
        }

        DB::table('room_user')
            ->where('game_room_id', $roomId)
            ->where('user_id', $playerId)
            ->update(['x' => $x, 'y' => $y, 'last_action_at' => $now]);

        return ['type' => 'move', 'x' => $x, 'y' => $y];
    }

    private function changeWeapon(int $roomId, int $playerId, array $input): array
    {
        $weapon = (int) $input['weapon'];

        DB::table('room_user')
            ->where('game_room_id', $roomId)
            ->where('user_id', $playerId)
            ->update(['weapon' => $weapon]);

        return ['type' => 'weapon', 'weapon' => $weapon];
    }

    private function fire(int $roomId, int $playerId, object $state, array $input, float $now): array
    {
        $weapon = (int) $state->weapon;
        $stats = self::WEAPONS[$weapon];

        if ($state->last_shot_at !== null && $now - (float) $state->last_shot_at < $stats['cooldown']) {
            throw new TooManyRequestsHttpException(null, 'El arma todavía se está recargando.');
        }

        DB::table('room_user')
            ->where('game_room_id', $roomId)
            ->where('user_id', $playerId)
            ->update(['last_shot_at' => $now]);

        $angle = (float) $input['angle'];

        for ($index = 0; $index < $stats['pellets']; $index++) {
            $offset = $stats['pellets'] === 1
                ? 0
                : ($index / ($stats['pellets'] - 1) - 0.5) * $stats['spread'];
            $projectileAngle = $angle + $offset;

            DB::table('game_projectiles')->insert([
                'id' => (string) Str::uuid(),
                'game_room_id' => $roomId,
                'user_id' => $playerId,
                'x' => (float) $state->x + cos($projectileAngle) * self::PLAYER_RADIUS,
                'y' => (float) $state->y + sin($projectileAngle) * self::PLAYER_RADIUS,
                'angle' => $projectileAngle,
                'speed' => $stats['speed'],
                'damage' => $stats['damage'],
                'spawned_at' => $now,
            ]);
        }

        return ['type' => 'fire', 'weapon' => $weapon];
    }

    private function advanceEnemies(int $roomId, float $delta, float $now): void
    {
        if ($delta <= 0) {
            return;
        }

        $players = DB::table('room_user')
            ->where('game_room_id', $roomId)
            ->where('health', '>', 0)
            ->get(['user_id', 'x', 'y', 'health'])
            ->keyBy('user_id');

        if ($players->isEmpty()) {
            return;
        }

        foreach (DB::table('game_enemies')->where('game_room_id', $roomId)->get() as $enemy) {
            if ($players->isEmpty()) {
                break;
            }

            $target = $players->sortBy(fn (object $candidate): float => hypot(
                (float) $candidate->x - (float) $enemy->x,
                (float) $candidate->y - (float) $enemy->y,
            ))->first();

            $dx = (float) $target->x - (float) $enemy->x;
            $dy = (float) $target->y - (float) $enemy->y;
            $distance = hypot($dx, $dy);

            if ($distance <= self::PLAYER_RADIUS + self::ENEMY_RADIUS) {
                if ($enemy->last_attack_at === null || $now - (float) $enemy->last_attack_at >= 0.8) {
                    $health = max(0, (int) $target->health - 10);

                    DB::table('room_user')
                        ->where('game_room_id', $roomId)
                        ->where('user_id', $target->user_id)
                        ->update(['health' => $health]);

                    $target->health = $health;

                    if ($health === 0) {
                        $players->forget($target->user_id);
                    }

                    DB::table('game_enemies')
                        ->where('id', $enemy->id)
                        ->update(['last_attack_at' => $now]);
                }

                continue;
            }

            $step = min((float) $enemy->speed * $delta, $distance);
            $nextX = (float) $enemy->x + ($dx / $distance) * $step;
            $nextY = (float) $enemy->y + ($dy / $distance) * $step;
            $x = $this->hitsBarrier($nextX, (float) $enemy->y, self::ENEMY_RADIUS) ? (float) $enemy->x : $nextX;
            $y = $this->hitsBarrier($x, $nextY, self::ENEMY_RADIUS) ? (float) $enemy->y : $nextY;

            DB::table('game_enemies')
                ->where('id', $enemy->id)
                ->update(['x' => $x, 'y' => $y]);
        }
    }

    private function advanceProjectiles(int $roomId, float $delta, float $now): void
    {
        if ($delta <= 0) {
            return;
        }

        foreach (DB::table('game_projectiles')->where('game_room_id', $roomId)->get() as $projectile) {
            if ($now - (float) $projectile->spawned_at > 2.5) {
                DB::table('game_projectiles')->where('id', $projectile->id)->delete();

                continue;
            }

            $nextX = (float) $projectile->x + cos((float) $projectile->angle) * (float) $projectile->speed * $delta;
            $nextY = (float) $projectile->y + sin((float) $projectile->angle) * (float) $projectile->speed * $delta;
            $hit = $this->projectileHitsBarrier((float) $projectile->x, (float) $projectile->y, $nextX, $nextY);

            if (! $hit) {
                foreach (DB::table('game_enemies')->where('game_room_id', $roomId)->get() as $enemy) {
                    if ($this->distanceToSegment((float) $enemy->x, (float) $enemy->y, (float) $projectile->x, (float) $projectile->y, $nextX, $nextY) > self::ENEMY_RADIUS + 4) {
                        continue;
                    }

                    $remainingHealth = max(0, (int) $enemy->health - (int) $projectile->damage);

                    if ($remainingHealth === 0) {
                        DB::table('game_enemies')->where('id', $enemy->id)->delete();
                        DB::table('room_user')
                            ->where('game_room_id', $roomId)
                            ->where('user_id', $projectile->user_id)
                            ->increment('score', 10);
                    } else {
                        DB::table('game_enemies')
                            ->where('id', $enemy->id)
                            ->update(['health' => $remainingHealth]);
                    }

                    $hit = true;

                    break;
                }
            }

            if ($hit || $nextX < 0 || $nextY < 0 || $nextX > self::WORLD_WIDTH || $nextY > self::WORLD_HEIGHT) {
                DB::table('game_projectiles')->where('id', $projectile->id)->delete();
            } else {
                DB::table('game_projectiles')
                    ->where('id', $projectile->id)
                    ->update(['x' => $nextX, 'y' => $nextY]);
            }
        }
    }

    private function finishWaveIfClear(int $roomId, object $roomState): void
    {
        if (DB::table('game_enemies')->where('game_room_id', $roomId)->exists()) {
            return;
        }

        $roomState->wave = (int) $roomState->wave + 1;
        $this->spawnWave($roomId, (int) $roomState->wave);
    }

    private function finishGameIfAllPlayersAreDead(int $roomId): bool
    {
        $hasLivingPlayers = DB::table('room_user')
            ->where('game_room_id', $roomId)
            ->where('health', '>', 0)
            ->exists();

        if (! $hasLivingPlayers) {
            DB::table('game_rooms')->where('id', $roomId)->update(['status' => 'finished']);
        }

        return ! $hasLivingPlayers;
    }

    private function spawnWave(int $roomId, int $wave): void
    {
        $spawnPoints = [
            [70, 70], [480, 45], [890, 70], [70, 270],
            [890, 270], [70, 470], [480, 495], [890, 470],
            [250, 55], [710, 55], [250, 485], [710, 485],
        ];
        $count = min(2 + $wave * 2, count($spawnPoints));
        $health = 50 + ($wave - 1) * 10;
        $now = now();

        foreach (array_slice($spawnPoints, 0, $count) as [$x, $y]) {
            DB::table('game_enemies')->insert([
                'game_room_id' => $roomId,
                'x' => $x,
                'y' => $y,
                'health' => $health,
                'max_health' => $health,
                'speed' => min(48 + ($wave - 1) * 4, 100),
                'last_attack_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function hitsBarrier(float $x, float $y, float $radius): bool
    {
        foreach (self::BARRIERS as $barrier) {
            $closestX = min(max($x, $barrier['x']), $barrier['x'] + $barrier['width']);
            $closestY = min(max($y, $barrier['y']), $barrier['y'] + $barrier['height']);

            if (hypot($x - $closestX, $y - $closestY) < $radius) {
                return true;
            }
        }

        return false;
    }

    private function projectileHitsBarrier(float $fromX, float $fromY, float $toX, float $toY): bool
    {
        for ($step = 1; $step <= 8; $step++) {
            $ratio = $step / 8;

            if ($this->hitsBarrier(
                $fromX + ($toX - $fromX) * $ratio,
                $fromY + ($toY - $fromY) * $ratio,
                2,
            )) {
                return true;
            }
        }

        return false;
    }

    private function distanceToSegment(float $x, float $y, float $fromX, float $fromY, float $toX, float $toY): float
    {
        $dx = $toX - $fromX;
        $dy = $toY - $fromY;
        $lengthSquared = $dx * $dx + $dy * $dy;

        if ($lengthSquared === 0.0) {
            return hypot($x - $fromX, $y - $fromY);
        }

        $ratio = min(max((($x - $fromX) * $dx + ($y - $fromY) * $dy) / $lengthSquared, 0), 1);

        return hypot($x - ($fromX + $ratio * $dx), $y - ($fromY + $ratio * $dy));
    }
}
