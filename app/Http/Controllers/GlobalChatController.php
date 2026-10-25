<?php

namespace App\Http\Controllers;

use App\Events\GlobalChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalChatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        broadcast(new GlobalChatMessage(
            $request->user()->name,
            $data['message'],
            now()->toIso8601String(),
        ));

        return response()->json(['sent' => true], 202);
    }
}
