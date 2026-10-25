<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('wave')->default(1);
            $table->double('last_simulated_at')->nullable();
            $table->string('status', 16)->default('active');
        });

        Schema::table('room_user', function (Blueprint $table) {
            $table->unsignedTinyInteger('health')->default(100);
            $table->unsignedInteger('score')->default(0);
        });

        Schema::create('game_enemies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_room_id')->constrained('game_rooms')->cascadeOnDelete();
            $table->float('x');
            $table->float('y');
            $table->unsignedSmallInteger('health');
            $table->unsignedSmallInteger('max_health');
            $table->float('speed');
            $table->double('last_attack_at')->nullable();
            $table->timestamps();
            $table->index(['game_room_id', 'id']);
        });

        Schema::create('game_projectiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('game_room_id')->constrained('game_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->float('x');
            $table->float('y');
            $table->float('angle');
            $table->unsignedSmallInteger('speed');
            $table->unsignedTinyInteger('damage');
            $table->double('spawned_at');
            $table->index(['game_room_id', 'spawned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_projectiles');
        Schema::dropIfExists('game_enemies');

        Schema::table('room_user', function (Blueprint $table) {
            $table->dropColumn(['health', 'score']);
        });

        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropColumn(['wave', 'last_simulated_at', 'status']);
        });
    }
};
