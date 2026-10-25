<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sala {{ $room->code }} | Arena</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="room-shell">
    <header class="room-topbar">
        <div>
            <p class="eyebrow">Arena · modo supervivencia</p>
            <h1 class="page-heading">Sala <span class="room-code">{{ $room->code }}</span></h1>
        </div>
        <a class="button" href="{{ route('lobby') }}">Volver al lobby</a>
    </header>

    <div class="game-layout" data-game-room
         data-room-code="{{ $room->code }}"
         data-player-id="{{ auth()->id() }}"
         data-action-url="{{ route('rooms.actions', $room) }}">
        <section class="panel game-panel" aria-label="Juego">
            <canvas class="game-canvas" data-game-canvas width="960" height="540" aria-label="Arena de juego. Usa WASD y el mouse para moverte y disparar."></canvas>
            <div class="game-status" data-game-status role="status" aria-live="polite"></div>
            @if ($room->owner_id === auth()->id())
                <form method="POST" action="{{ route('rooms.restart', $room) }}" data-restart-form @if ($state['status'] !== 'finished') hidden @endif>
                    @csrf
                    <button type="submit">Jugar de nuevo</button>
                </form>
            @endif
            <div class="game-hud" aria-live="polite">
                <span>Oleada <strong data-wave>{{ $state['wave'] }}</strong></span>
                <span>Vida <strong data-health>100</strong></span>
                <span>Puntos <strong data-score>0</strong></span>
            </div>
            <div class="weapon-bar" aria-label="Seleccionar arma">
                <button type="button" data-weapon="1" aria-pressed="true">1 · Pistola</button>
                <button type="button" data-weapon="2" aria-pressed="false">2 · Escopeta</button>
                <button type="button" data-weapon="3" aria-pressed="false">3 · Rifle</button>
            </div>
            <div class="mobile-controls" aria-label="Controles táctiles">
                <div class="joystick" data-stick="move" aria-label="Joystick de movimiento">
                    <span data-stick-thumb></span>
                </div>
                <div class="mobile-aim-group">
                    <div class="joystick" data-stick="aim" aria-label="Joystick de apuntado">
                        <span data-stick-thumb></span>
                    </div>
                    <button type="button" class="fire-button" data-fire-button>Disparar</button>
                </div>
            </div>
        </section>

        <aside class="panel game-sidebar">
            <h2>Jugadores</h2>
            <p class="subtle">Host: {{ $room->owner->name }}</p>
            <p class="connection-status" data-connection-status data-connection-state="connecting" role="status" aria-live="polite">Conectando con tiempo real...</p>
            <ul class="player-list" data-player-list aria-live="polite"></ul>
            <p class="status" data-room-notice role="status" aria-live="polite"></p>
            <hr>
            <h2>Invita al equipo</h2>
            <div class="qr-card">
                <canvas data-room-qr="{{ route('rooms.join', $room->code) }}" role="img" aria-label="Código QR para unirse a la sala {{ $room->code }}"></canvas>
                <a href="{{ route('rooms.join', $room->code) }}">Compartir invitación</a>
            </div>
            <p class="subtle controls-help">PC: WASD/flechas, mouse para apuntar y disparar, 1–3 para cambiar arma.</p>
        </aside>
    </div>

    <script type="application/json" data-initial-state>@json($state)</script>
</main>
</body>
</html>
