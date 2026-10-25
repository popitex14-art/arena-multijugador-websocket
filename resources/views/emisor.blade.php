<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Emisor</title>
@vite(['resources/css/app.css','resources/js/app.js'])
<style>
body { font-family: system-ui, sans-serif; padding: 24px; }
.wrap { max-width: 760px; margin: 0 auto; }
.box { border: 1px solid #ddd; border-radius: 12px; padding: 16px; }
label { font-weight: 600; }
textarea, input, button { width: 100%; padding: 10px; margin-top: 6px; }
.row { display: grid; gap: 12px; }
</style>
</head>
<body>
<div class="wrap">
<h1>Emisor</h1>
@if (session('ok'))
<div style="background:#e7f7ee;border:1px solid
#b7e2c8;padding:8px;border-radius:8px;margin-bottom:12px;">
{{ session('ok') }}
</div>
@endif
@if ($errors->any())
<div style="background:#fde8e8;border:1px solid
#f5c2c2;padding:8px;border-radius:8px;margin-bottom:12px;">
{{ $errors->first() }}
</div>
@endif
<form class="box" method="POST" action="{{ route('emitir') }}">
@csrf
<div class="row">
<div>
<label>Texto a mostrar</label>
<input type="text" name="newText" placeholder="Ej: Ahora atendiendo: A-037"
required>
</div>
<div>
<label>CSS inline (opcional)</label>
<textarea name="newStyle" rows="6" placeholder="Ej:
background:#111;color:#fff;padding:24px;border-radius:16px;font-size:28px;text-align:
center;"></textarea>
</div>
<button type="submit">Emitir</button>
</div>
</form>
<p style="margin-top:16px;">
Abrí <code>/receptor</code> en otra pestaña/ventana para ver los cambios en
tiempo real.
</p>
</div>
</body>
</html