<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_user', function (Blueprint $table) {
            $table->float('x')->default(480);
            $table->float('y')->default(270);
            $table->unsignedTinyInteger('weapon')->default(1);
            $table->double('last_action_at')->nullable();
            $table->double('last_shot_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_user', function (Blueprint $table) {
            $table->dropColumn(['x', 'y', 'weapon', 'last_action_at', 'last_shot_at']);
        });
    }
};
