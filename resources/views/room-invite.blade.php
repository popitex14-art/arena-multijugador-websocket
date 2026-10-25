<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unirse a {{ $room->code }} | Arena</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="room-shell">
    @if ($errors->any())
        <ul class="error-list" role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    <p class="eyebrow">Invitación a sala privada</p>
    <h1 class="page-heading">Unirse a <span class="room-code">{{ $room->code }}</span></h1>
    <section class="panel stack">
        <p class="subtle">Host: {{ $room->owner->name }} · {{ $room->players()->count() }} jugador(es)</p>
        <form method="POST" action="{{ route('rooms.enter', $room->code) }}">
            @csrf
            <button type="submit">Unirme a la sala</button>
        </form>
        <a href="{{ route('lobby') }}">Volver al lobby</a>
    </section>
    @guest
        <section class="panel stack" style="margin-top: 1rem">
            <p class="subtle">Primero elige un apodo. Después volverás a esta invitación para unirte.</p>
            <form class="stack" method="POST" action="{{ route('player.register') }}">
                @csrf
                <input type="hidden" name="room_code" value="{{ $room->code }}">
                <label for="player-name">Tu apodo</label>
                <input id="player-name" name="name" value="{{ old('name') }}" maxlength="24" minlength="2" autocomplete="nickname" required>
                <button type="submit">Continuar</button>
            </form>
        </section>
    @endguest
</main>
</body>
</html>
