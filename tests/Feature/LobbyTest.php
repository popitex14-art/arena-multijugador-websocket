<?php

namespace Tests\Feature;

use App\Events\GlobalChatMessage;
use App\Events\RoomGameAction;
use App\Events\RoomPlayerJoined;
use App\Models\GameRoom;
use App\Models\User;
use App\Services\GameStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class LobbyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_choose_a_name_and_enter_the_lobby(): void
    {
        $this->get(route('lobby'))
            ->assertOk()
            ->assertSee('Lobby global')
            ->assertSee('data-connection-status', false);

        $this->post(route('player.register'), ['name' => 'Pixel Ranger'])
            ->assertRedirect(route('lobby'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['name' => 'Pixel Ranger']);
    }

    public function test_player_can_create_a_room_and_join_from_an_invitation(): void
    {
        $owner = User::factory()->create(['name' => 'Host']);
        Event::fake([RoomPlayerJoined::class]);

        $this->actingAs($owner)
            ->post(route('rooms.store'))
            ->assertRedirect();

        $room = GameRoom::firstOrFail();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $room->code);
        $this->assertTrue($room->players()->whereKey($owner->id)->exists());
        $this->assertSame(1, $room->fresh()->wave);
        $this->assertDatabaseCount('game_enemies', 4);
        Event::assertDispatched(RoomPlayerJoined::class);

        $guest = User::factory()->create(['name' => 'Guest']);
        $this->actingAs($guest)
            ->get(route('rooms.join', $room->code))
            ->assertOk()
            ->assertSee('Unirme a la sala');

        $this->assertFalse($room->players()->whereKey($guest->id)->exists());

        $this->post(route('rooms.enter', $room->code))
            ->assertRedirect(route('rooms.show', $room));

        $this->assertTrue($room->players()->whereKey($guest->id)->exists());
    }

    public function test_guest_registration_from_room_invitation_returns_to_that_room(): void
    {
        $owner = User::factory()->create();
        $room = GameRoom::create(['code' => 'ABC123', 'owner_id' => $owner->id]);
        $room->players()->attach($owner->id);

        $this->get(route('rooms.join', $room->code))
            ->assertOk()
            ->assertSee('room_code');

        $this->post(route('player.register'), [
            'name' => 'QR Player',
            'room_code' => $room->code,
        ])->assertRedirect(route('rooms.join', $room->code));
    }

    public function test_only_room_members_can_open_a_room(): void
    {
        $owner = User::factory()->create();
        $room = GameRoom::create([
            'code' => 'ABC123',
            'owner_id' => $owner->id,
        ]);
        $room->players()->attach($owner->id);

        $this->actingAs(User::factory()->create())
            ->get(route('rooms.show', $room))
            ->assertForbidden();
    }

    public function test_only_the_owner_sees_the_restart_button_after_game_over(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $room = GameRoom::create([
            'code' => 'RESETUI',
            'owner_id' => $owner->id,
        ]);
        DB::table('game_rooms')->where('id', $room->id)->update(['status' => 'finished']);
        $room->players()->attach([$owner->id, $member->id]);

        $this->actingAs($owner)
            ->get(route('rooms.show', $room))
            ->assertOk()
            ->assertSee('data-restart-form', false)
            ->assertSee('Jugar de nuevo');

        $this->actingAs($member)
            ->get(route('rooms.show', $room))
            ->assertOk()
            ->assertDontSee('data-restart-form', false);
    }

    public function test_room_member_can_open_the_responsive_game_view(): void
    {
        $player = User::factory()->create(['name' => 'Ranger']);
        $room = GameRoom::create(['code' => 'VIEW12', 'owner_id' => $player->id]);
        $room->players()->attach($player->id);

        $this->actingAs($player)
            ->get(route('rooms.show', $room))
            ->assertOk()
            ->assertSee('data-game-canvas', false)
            ->assertSee('data-stick="move"', false)
            ->assertSee('data-action-url=', false)
            ->assertSee('data-connection-status', false)
            ->assertSee('"name":"Ranger"', false);
    }

    public function test_room_owner_can_restart_a_finished_game_and_broadcast_the_new_state(): void
    {
        $owner = User::factory()->create(['name' => 'Host']);
        $teammate = User::factory()->create(['name' => 'Teammate']);
        $room = GameRoom::create([
            'code' => 'RESET1',
            'owner_id' => $owner->id,
        ]);
        DB::table('game_rooms')->where('id', $room->id)->update([
            'wave' => 3,
            'status' => 'finished',
        ]);
        $room->players()->attach($owner->id, [
            'x' => 120,
            'y' => 200,
            'weapon' => 3,
            'health' => 0,
            'score' => 50,
            'last_action_at' => 123.0,
            'last_shot_at' => 123.0,
        ]);
        $room->players()->attach($teammate->id, [
            'x' => 800,
            'y' => 400,
            'weapon' => 2,
            'health' => 40,
            'score' => 30,
        ]);
        DB::table('game_enemies')->insert([
            'game_room_id' => $room->id,
            'x' => 200,
            'y' => 200,
            'health' => 10,
            'max_health' => 50,
            'speed' => 48,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Event::fake([RoomGameAction::class]);

        $this->actingAs($owner)
            ->post(route('rooms.restart', $room))
            ->assertRedirect(route('rooms.show', $room));

        $this->assertDatabaseHas('game_rooms', [
            'id' => $room->id,
            'wave' => 1,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('game_enemies', 4);
        $this->assertDatabaseCount('game_projectiles', 0);

        foreach ([$owner, $teammate] as $player) {
            $state = DB::table('room_user')
                ->where('game_room_id', $room->id)
                ->where('user_id', $player->id)
                ->first();

            $this->assertSame(480.0, (float) $state->x);
            $this->assertSame(270.0, (float) $state->y);
            $this->assertSame(1, (int) $state->weapon);
            $this->assertSame(100, (int) $state->health);
            $this->assertSame(0, (int) $state->score);
            $this->assertNull($state->last_action_at);
            $this->assertNull($state->last_shot_at);
        }

        Event::assertDispatched(RoomGameAction::class, fn (RoomGameAction $event): bool => $event->roomCode === 'RESET1'
            && $event->action['type'] === 'restart'
            && $event->action['state']['status'] === 'active'
            && $event->action['state']['wave'] === 1);
    }

    public function test_only_the_room_owner_can_restart_a_finished_game(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $room = GameRoom::create([
            'code' => 'RESET2',
            'owner_id' => $owner->id,
            'status' => 'finished',
        ]);
        $room->players()->attach([$owner->id, $member->id]);

        $this->actingAs($member)
            ->post(route('rooms.restart', $room))
            ->assertForbidden();
    }

    public function test_room_owner_cannot_restart_a_game_that_is_still_active(): void
    {
        $owner = User::factory()->create();
        $room = GameRoom::create([
            'code' => 'RESET3',
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);
        $room->players()->attach($owner->id);

        $this->actingAs($owner)
            ->postJson(route('rooms.restart', $room))
            ->assertConflict()
            ->assertJsonPath('message', 'La partida todavía está en curso.');
    }

    public function test_chat_messages_are_broadcast_with_the_player_name(): void
    {
        Event::fake([GlobalChatMessage::class]);
        $player = User::factory()->create(['name' => 'Pixel Ranger']);

        $this->actingAs($player)
            ->postJson(route('chat.messages.store'), ['message' => 'Ready to play'])
            ->assertAccepted()
            ->assertJson(['sent' => true]);

        Event::assertDispatched(GlobalChatMessage::class, function (GlobalChatMessage $event): bool {
            return $event->playerName === 'Pixel Ranger'
                && $event->message === 'Ready to play'
                && $event->sentAt !== '';
        });
    }

    public function test_chat_rejects_messages_longer_than_500_characters(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('chat.messages.store'), ['message' => str_repeat('x', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_room_member_can_move_and_broadcast_the_authoritative_position(): void
    {
        $player = User::factory()->create(['name' => 'Ranger']);
        $room = GameRoom::create(['code' => 'MOV123', 'owner_id' => $player->id]);
        $room->players()->attach($player->id);
        Event::fake([RoomGameAction::class]);

        $this->actingAs($player)
            ->postJson(route('rooms.actions', $room), [
                'type' => 'move',
                'dx' => 1,
                'dy' => 0,
            ])
            ->assertAccepted()
            ->assertJsonPath('action.type', 'move');

        $this->assertGreaterThan(480, (float) $room->players()->whereKey($player->id)->first()->pivot->x);
        Event::assertDispatched(RoomGameAction::class, fn (RoomGameAction $event): bool => $event->playerId === $player->id
            && $event->roomCode === 'MOV123'
            && $event->action['type'] === 'move');
    }

    public function test_room_member_can_fire_the_selected_weapon(): void
    {
        $player = User::factory()->create();
        $room = GameRoom::create(['code' => 'FIR123', 'owner_id' => $player->id]);
        $room->players()->attach($player->id);
        Event::fake([RoomGameAction::class]);

        $this->actingAs($player)
            ->postJson(route('rooms.actions', $room), [
                'type' => 'fire',
                'angle' => 0,
            ])
            ->assertAccepted()
            ->assertJsonPath('action.state.projectiles.0.damage', 25);

        Event::assertDispatched(RoomGameAction::class, fn (RoomGameAction $event): bool => $event->action['type'] === 'fire'
            && count($event->action['state']['projectiles']) === 1);
    }

    public function test_non_member_cannot_send_room_game_actions(): void
    {
        $owner = User::factory()->create();
        $room = GameRoom::create(['code' => 'NOPE12', 'owner_id' => $owner->id]);
        $room->players()->attach($owner->id);

        $this->actingAs(User::factory()->create())
            ->postJson(route('rooms.actions', $room), [
                'type' => 'move',
                'dx' => 1,
                'dy' => 0,
            ])
            ->assertForbidden();
    }

    public function test_game_action_requires_valid_weapon_and_movement_values(): void
    {
        $player = User::factory()->create();
        $room = GameRoom::create(['code' => 'VAL123', 'owner_id' => $player->id]);
        $room->players()->attach($player->id);

        $this->actingAs($player)
            ->postJson(route('rooms.actions', $room), [
                'type' => 'weapon',
                'weapon' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weapon');

        $this->postJson(route('rooms.actions', $room), [
            'type' => 'move',
            'dx' => 10,
            'dy' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('dx');
    }

    public function test_authoritative_projectile_kills_enemy_scores_and_starts_the_next_wave(): void
    {
        $player = User::factory()->create();
        $room = GameRoom::create(['code' => 'KILL12', 'owner_id' => $player->id]);
        $room->players()->attach($player->id);
        $gameState = app(GameStateService::class);
        $gameState->start($room);
        DB::table('game_rooms')->where('id', $room->id)->update(['last_simulated_at' => 100.0]);
        DB::table('game_enemies')->where('game_room_id', $room->id)->delete();
        DB::table('game_enemies')->insert([
            'game_room_id' => $room->id,
            'x' => 520,
            'y' => 270,
            'health' => 20,
            'max_health' => 20,
            'speed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $action = $gameState->apply($room, $player, ['type' => 'fire', 'angle' => 0], 100.1);

        $this->assertSame(2, $action['state']['wave']);
        $this->assertSame(10, (int) DB::table('room_user')->where('user_id', $player->id)->value('score'));
        $this->assertDatabaseCount('game_enemies', 6);
        $this->assertDatabaseCount('game_projectiles', 0);
    }

    public function test_enemy_contact_reduces_player_health_and_all_dead_players_end_the_match(): void
    {
        $player = User::factory()->create();
        $room = GameRoom::create(['code' => 'HURT12', 'owner_id' => $player->id]);
        $room->players()->attach($player->id, ['health' => 10]);
        $gameState = app(GameStateService::class);
        $gameState->start($room);
        DB::table('game_rooms')->where('id', $room->id)->update(['last_simulated_at' => 200.0]);
        DB::table('game_enemies')->where('game_room_id', $room->id)->delete();
        DB::table('game_enemies')->insert([
            [
                'game_room_id' => $room->id,
                'x' => 505,
                'y' => 270,
                'health' => 50,
                'max_health' => 50,
                'speed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'game_room_id' => $room->id,
                'x' => 510,
                'y' => 270,
                'health' => 50,
                'max_health' => 50,
                'speed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $action = $gameState->apply($room, $player, ['type' => 'heartbeat'], 200.1);

        $this->assertSame(0, $action['state']['players'][0]['health']);
        $this->assertSame('finished', $action['state']['status']);
        $this->assertDatabaseHas('game_rooms', ['id' => $room->id, 'status' => 'finished']);
    }

    public function test_server_enforces_the_selected_weapons_fire_cooldown(): void
    {
        $player = User::factory()->create();
        $room = GameRoom::create(['code' => 'COOL12', 'owner_id' => $player->id]);
        $room->players()->attach($player->id);
        $gameState = app(GameStateService::class);
        $gameState->start($room);

        $gameState->apply($room, $player, ['type' => 'fire', 'angle' => 0], 300.0);

        $this->expectException(TooManyRequestsHttpException::class);
        $gameState->apply($room, $player, ['type' => 'fire', 'angle' => 0], 300.05);
    }

    public function test_server_removes_projectiles_that_hit_an_arena_barrier(): void
    {
        $player = User::factory()->create();
        $room = GameRoom::create(['code' => 'WALL12', 'owner_id' => $player->id]);
        $room->players()->attach($player->id, ['x' => 630, 'y' => 160]);
        $gameState = app(GameStateService::class);
        $gameState->start($room);
        DB::table('game_rooms')->where('id', $room->id)->update(['last_simulated_at' => 400.0]);

        $gameState->apply($room, $player, ['type' => 'fire', 'angle' => 0], 400.1);

        $this->assertDatabaseCount('game_projectiles', 0);
    }
}
