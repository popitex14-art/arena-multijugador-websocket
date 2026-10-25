<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LobbyController extends Controller
{
    public function index(): View
    {
        $rooms = Auth::user()?->rooms()
            ->with('owner:id,name')
            ->orderByDesc('game_rooms.created_at')
            ->orderByDesc('game_rooms.id')
            ->get() ?? collect();

        return view('lobby', compact('rooms'));
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:24', 'regex:/\S/u'],
            'room_code' => ['nullable', 'string', 'size:6', Rule::exists('game_rooms', 'code')],
        ]);

        $player = Auth::user();

        if ($player) {
            $player->update(['name' => trim($data['name'])]);
        } else {
            $player = User::create([
                'name' => trim($data['name']),
                'email' => 'player+'.Str::uuid().'@players.invalid',
                'password' => Str::random(64),
            ]);

            Auth::login($player);
            $request->session()->regenerate();
        }

        if (isset($data['room_code'])) {
            return redirect()->route('rooms.join', $data['room_code']);
        }

        return redirect()->intended(route('lobby'));
    }
}
