<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## Arena multijugador en tiempo real

La ruta `/` contiene el lobby, chat global y las invitaciones a salas privadas. Los jugadores eligen un apodo; las salas persisten en la base de datos y sus canales `game-room.{code}` son privados y verifican la membresía del jugador. El QR apunta a una pantalla de invitación; el jugador debe registrarse o confirmar la unión antes de entrar.

Para desarrollo local, `REVERB_HOST` y `REVERB_PORT` son el destino al que Laravel transmite (`127.0.0.1:8080`); `REVERB_SERVER_HOST` y `REVERB_SERVER_PORT` determinan dónde escucha Reverb (`0.0.0.0:8080`). El navegador utiliza las variables `VITE_REVERB_*`.

```sh
php artisan migrate
npm install
npm run dev
php artisan serve --host=127.0.0.1 --port=8000
php artisan reverb:start --host=127.0.0.1 --port=8080
```

Para acceso desde otra computadora, `tunnel-gateway.mjs` unifica Laravel y Reverb detrás de un único puerto. Las peticiones normales y assets se envían a Laravel (`127.0.0.1:8000`); las rutas `/app/*` y todas las conexiones WebSocket se envían a Reverb (`127.0.0.1:8080`). El gateway escucha solo en `127.0.0.1:9000`, añade cabeceras `X-Forwarded-*` normalizadas y confía únicamente en el proxy local en `bootstrap/app.php`.

Configura `APP_URL` y `VITE_REVERB_HOST` con el mismo hostname público asignado por tu proveedor de túnel. En este proyecto, Ngrok asignó `https://bullish-press-confident.ngrok-free.dev`. Para Ngrok gratuito, no pases `--url` ni inventes un subdominio; inicia un túnel sin hostname personalizado. Si el dominio automático cambia, actualiza ambas variables y recompila porque Vite incorpora `VITE_REVERB_*` al JavaScript durante el build. Para el gateway público usa assets compilados, no `npm run dev`:

```powershell
php artisan optimize:clear
npm run build
```

Abre cuatro terminales en la raíz del proyecto y deja cada proceso activo:

```powershell
# Terminal 1: Laravel
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
# Terminal 2: Reverb
php artisan reverb:start --host=127.0.0.1 --port=8080
```

```powershell
# Terminal 3: gateway HTTP/WebSocket
npm run gateway
```

```powershell
# Terminal 4: un único túnel público hacia el gateway
ngrok http 9000
```

Ngrok debe mostrar `Forwarding https://<dominio-asignado> -> http://localhost:9000`. Usa ese mismo hostname en `APP_URL` y `VITE_REVERB_HOST`; configura `VITE_REVERB_PORT=443` y `VITE_REVERB_SCHEME=https`. El endpoint gratuito de Ngrok muestra una página intermedia de advertencia al visitante; hay que aceptarla una vez antes de usar la aplicación. Un túnel de Cloudflare u otro proveedor puede apuntar igualmente al puerto 9000.

No publiques credenciales de Reverb ni authtokens de Ngrok. No configures el host público como `REVERB_HOST`: Laravel debe transmitir directamente al Reverb local (`localhost:8080`); solo el navegador usa el hostname público mediante `VITE_REVERB_HOST`.

El prototipo actual incorpora una arena Canvas 2D con personajes y zombis pixel art animados, suelo con textura, obstáculos con relieve y efectos de disparo e impacto. Incluye movimiento, apuntado, tres armas y proyectiles sincronizados por Reverb, con controles WASD/flechas y joysticks táctiles en móvil. El servidor valida colisiones de movimiento/disparos, controla enemigos, salud, puntos y oleadas, y persiste ese estado en SQLite. Al terminar la partida, el anfitrión puede reiniciarla para todo el equipo desde el botón **Jugar de nuevo**. Cada cliente envía un heartbeat cada 100 ms mientras la partida está activa; el servidor simula el mundo y publica snapshots por el canal privado. Para pruebas locales, SQLite es suficiente; cuando haya varias instancias web o cientos de salas simultáneas, migra a PostgreSQL y Redis para locks, cache y escalado.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
