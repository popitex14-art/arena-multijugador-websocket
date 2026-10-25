<?php

use App\Http\Controllers\GameActionController;
use App\Http\Controllers\GameRoomController;
use App\Http\Controllers\GlobalChatController;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\RemoteStyleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LobbyController::class, 'index'])->name('lobby');
Route::post('/player', [LobbyController::class, 'register'])->middleware('throttle:5,1')->name('player.register');
Route::post('/chat/messages', [GlobalChatController::class, 'store'])
    ->middleware(['auth', 'throttle:20,1'])
    ->name('chat.messages.store');

Route::middleware('auth')->group(function () {
    Route::post('/rooms', [GameRoomController::class, 'store'])->name('rooms.store');
    Route::post('/rooms/{code}/join', [GameRoomController::class, 'enter'])->name('rooms.enter');
    Route::post('/rooms/{room}/restart', [GameRoomController::class, 'restart'])->name('rooms.restart');
    Route::post('/rooms/{room}/actions', GameActionController::class)
        ->middleware('throttle:game-actions')
        ->name('rooms.actions');
    Route::get('/rooms/{room}', [GameRoomController::class, 'show'])->name('rooms.show');
});

Route::get('/rooms/{code}/join', [GameRoomController::class, 'join'])->name('rooms.join');
Route::get('/login', fn () => redirect()->route('lobby'))->name('login');

Route::get('/emisor', [RemoteStyleController::class, 'emisor'])->name('emisor');
Route::post('/emitir', [RemoteStyleController::class, 'emitir'])->name('emitir');
Route::get('/receptor', [RemoteStyleController::class, 'receptor'])->name('receptor');
