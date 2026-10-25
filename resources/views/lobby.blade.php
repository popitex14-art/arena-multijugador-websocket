<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Lobby | Arena</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="lobby-shell">
    <header>
        <p class="eyebrow">Arena · multijugador en tiempo real</p>
        <h1 class="page-heading">Lobby global</h1>
    </header>

    @if ($errors->any())
        <ul class="error-list" role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="lobby-grid">
        <section class="panel" aria-labelledby="chat-title">
            <h2 id="chat-title">Chat global</h2>
            <p class="subtle">Los mensajes se transmiten en vivo a todos los jugadores conectados.</p>
            <p class="connection-status" data-connection-status data-connection-state="connecting" role="status" aria-live="polite">Conectando con tiempo real...</p>
            <ul class="chat-list" data-chat-messages aria-live="polite" aria-label="Mensajes del chat">
                <li class="empty-state">¡Bienvenido! El chat está listo para empezar.</li>
            </ul>
            @auth
                <form class="inline-form" data-chat-form action="{{ route('chat.messages.store') }}">
                    @csrf
                    <label class="sr-only" for="chat-message">Mensaje</label>
                    <input id="chat-message" name="message" maxlength="500" placeholder="Escribe un mensaje…" required>
                    <button type="submit">Enviar</button>
                </form>
                <p class="status" data-chat-status role="status"></p>
            @else
                <p class="subtle">Elige un apodo para participar en el chat y crear o unirte a una sala.</p>
            @endauth
        </section>

        <section class="panel" aria-labelledby="rooms-title">
            <h2 id="rooms-title">Salas privadas</h2>
            @guest
                <form class="stack" method="POST" action="{{ route('player.register') }}">
                    @csrf
                    <label for="player-name">Tu apodo</label>
                    <input id="player-name" name="name" value="{{ old('name') }}" maxlength="24" minlength="2" autocomplete="nickname" placeholder="Ej. PixelRanger" required>
                    <button type="submit">Entrar al lobby</button>
                </form>
            @else
                <p class="subtle">Jugando como <strong>{{ auth()->user()->name }}</strong></p>
                <form method="POST" action="{{ route('rooms.store') }}">
                    @csrf
                    <button type="submit">Crear una sala</button>
                </form>

                <form class="stack" data-room-join-form data-join-url="{{ url('/rooms') }}">
                    <label for="room-code">Unirse con código</label>
                    <div class="inline-form">
                        <input id="room-code" name="code" maxlength="6" pattern="[A-Za-z0-9]{6}" placeholder="ABC123" required>
                        <button type="submit">Unirse</button>
                    </div>
                </form>

                @if ($rooms->isNotEmpty())
                    <h3>Mis salas</h3>
                    <ul class="room-list">
                        @foreach ($rooms as $room)
                            <li>
                                <span><span class="room-code">{{ $room->code }}</span><br><small>Host: {{ $room->owner->name }}</small></span>
                                <a class="button" href="{{ route('rooms.show', $room) }}">Abrir</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="empty-state">Aún no estás en ninguna sala. Crea una o ingresa un código.</p>
                @endif
            @endguest
        </section>
    </div>
</main>
</body>
</html>
