# Arena multijugador web en tiempo real

## Guion informativo para la exposicion

### 1. Presentacion del proyecto

Este proyecto consiste en un prototipo de videojuego web multijugador inspirado en los juegos de accion cenital 2D, como Boxhead. Esta construido sobre una aplicacion Laravel existente y permite que varias personas se conecten desde distintos equipos para jugar en la misma sala en tiempo real.

El objetivo principal fue resolver dos desafios: implementar una partida compartida con estado sincronizado y permitir que otras computadoras accedan a la aplicacion y a sus conexiones WebSocket a traves de Internet.

### 2. Tecnologias utilizadas

- **Laravel y PHP:** rutas, autenticacion por nombre de jugador, salas, validacion de acciones y logica del juego.
- **SQLite:** persistencia local de salas y estado de la partida durante esta etapa del prototipo.
- **Laravel Reverb y Laravel Echo:** comunicacion en tiempo real mediante WebSockets y difusion de eventos a los clientes.
- **JavaScript y Canvas 2D:** controles, dibujo de la arena y actualizacion visual del juego en el navegador.
- **Vite:** compilacion de CSS y JavaScript para servir recursos estaticos en la version publica.
- **Node.js y `http-proxy`:** gateway que unifica el trafico web y WebSocket en un unico puerto.
- **Ngrok:** tunel publico para que otras computadoras puedan llegar al gateway local.

### 3. Funcionalidades implementadas

#### Lobby y salas

- Lobby global con chat en vivo.
- Creacion de salas privadas identificadas por un codigo.
- Invitaciones mediante enlace y codigo QR.
- Canales privados de Reverb para cada sala; el servidor verifica que el jugador pertenezca a ella.

#### Partida

- Arena 2D dibujada en un canvas.
- Personajes y zombis pixel art animados, suelo texturizado, obstáculos con relieve y efectos visuales de disparo e impacto.
- Controles de teclado y mouse en PC, y controles tactiles en pantallas moviles.
- Movimiento, apuntado, disparos y tres armas.
- Enemigos, colisiones, salud, puntuacion, oleadas y fin de partida.
- Boton **Jugar de nuevo** para el anfitrion cuando termina la partida. Al reiniciar, el equipo recibe el nuevo estado de la sala.

El servidor valida las acciones y mantiene el estado compartido. Los clientes envian acciones y reciben actualizaciones de la partida por Reverb; no dependen de que cada navegador calcule por su cuenta el resultado final del juego.

### 4. Arquitectura de red

En el equipo que ejecuta el proyecto hay cuatro servicios locales:

| Servicio | Puerto | Funcion |
|---|---:|---|
| Laravel | 8000 | Paginas, recursos, sesiones y acciones del juego |
| Reverb | 8080 | Eventos y conexiones WebSocket |
| Gateway Node.js | 9000 | Enrutamiento de trafico web y WebSocket |
| Ngrok | publico hacia 9000 | Tunel de acceso desde Internet |

El recorrido de una solicitud es:

```text
Navegador remoto
       |
       | HTTPS y WSS
       v
Tunel Ngrok (un unico endpoint)
       |
       v
Gateway Node.js :9000
       |                         |
       | paginas y recursos      | WebSocket y rutas /app/*
       v                         v
Laravel :8000                 Reverb :8080
```

El gateway esta implementado en `tunnel-gateway.mjs`. Las solicitudes HTTP normales van a Laravel; las rutas de Reverb `/app` y `/app/*`, y las conexiones WebSocket (upgrade), van a Reverb. Tambien normaliza las cabeceras `X-Forwarded-*` para que Laravel conozca el esquema y el host publicos cuando corresponda. Laravel confia unicamente en el proxy local, no indiscriminadamente en cualquier proxy.

### 5. Problemas encontrados y como se resolvieron

#### A. URL publica incorrecta

Al principio, Laravel generaba enlaces y recursos como si la aplicacion siguiera funcionando en `localhost`. Esto ocasionaba problemas de acceso desde otra computadora. Se corrigio la configuracion de `APP_URL` para usar la direccion publica actual y se separaron las direcciones del backend y del navegador:

- `APP_URL`: host publico usado para generar enlaces de Laravel.
- `REVERB_HOST` y `REVERB_PORT`: destino local al que Laravel envia los eventos.
- `VITE_REVERB_HOST`, `VITE_REVERB_PORT` y `VITE_REVERB_SCHEME`: endpoint publico utilizado por el navegador para conectarse por WebSocket.

Es importante no poner el host publico en `REVERB_HOST`: el servidor Laravel transmite a Reverb local; el navegador llega a Reverb a traves del tunel.

#### B. Sitio sin estilos y errores de recursos

Desde otro equipo, la pagina llego a mostrarse sin el formato esperado. Se encontro que el archivo `public/hot` apuntaba a un servidor Vite de desarrollo local (`[::1]:5173`), inaccesible desde Internet. Se retiro ese indicador obsoleto y se compilaron los recursos para produccion con `npm run build`.

Para acceder desde fuera se utilizan los archivos compilados de `public/build`, no el servidor de desarrollo Vite.

#### C. Limite de Ngrok gratuito con dos tuneles

La primera configuracion necesitaba exponer por separado Laravel y Reverb. Ngrok gratuito no permitio crear un segundo endpoint con un subdominio personalizado y devolvio `ERR_NGROK_313`, indicando que esa funcion requeria un plan pago.

En vez de depender de dos tuneles o de pagar por un subdominio personalizado, se agrego el gateway Node.js. Asi, un unico tunel Ngrok apunta al puerto 9000 y el gateway distribuye internamente cada tipo de solicitud al servicio correcto. Se inicia el tunel sin solicitar un hostname personalizado; Ngrok asigna el dominio disponible para la cuenta.

#### D. Errores de conexion y respuestas que no eran JSON

Durante las pruebas aparecieron errores de red y respuestas HTTP de error, por ejemplo limites de solicitudes o paginas HTML del servidor/tunel. El cliente esperaba JSON y podia fallar al intentar interpretar una respuesta HTML como si fuera JSON.

Se ajusto el manejo del cliente para distinguir errores HTTP y de conectividad, y mostrar mensajes comprensibles, incluyendo limite de acciones, sesion vencida y perdida de conexion. Ademas, se reviso el envio frecuente de acciones y la sincronizacion de los snapshots.

#### E. Sincronizacion del estado del jugador

Se corrigio una referencia obsoleta al jugador local al aplicar snapshots. El cliente ahora actualiza el objeto que utilizan los controles y el renderizado, evitando que movimiento o disparos dependan de datos anteriores.

### 6. Como ejecutar el proyecto para una demostracion

Desde la carpeta del proyecto, abrir cuatro terminales y mantenerlas abiertas:

**Terminal 1 — Laravel**

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

**Terminal 2 — Reverb**

```powershell
php artisan reverb:start --host=127.0.0.1 --port=8080
```

**Terminal 3 — Gateway**

```powershell
npm run gateway
```

**Terminal 4 — Tunel publico**

```powershell
ngrok http 9000
```

Ngrok mostrara una URL HTTPS publica. Los valores de `APP_URL` y `VITE_REVERB_HOST` deben corresponder al hostname que Ngrok asigno en ese momento; para Reverb en el navegador se usa el puerto 443 y el esquema HTTPS. Si el hostname cambia, hay que actualizar esos valores, limpiar la configuracion de Laravel y volver a compilar el frontend:

```powershell
php artisan optimize:clear
npm run build
```

Antes de la demostracion, comprobar la URL publica, abrirla en dos dispositivos y aceptar la pagina intermedia de advertencia de Ngrok si aparece. No compartir authtokens ni credenciales en una presentacion o captura de pantalla.

### 7. Validacion realizada

- Se comprobo el acceso al juego desde dos computadoras y el usuario confirmo que ambas podian jugar juntas.
- Se probaron localmente el enrutamiento HTTP y la conexion WebSocket del gateway.
- Se verificaron acciones de juego como movimiento y disparo.
- Se ejecutaron las pruebas automatizadas: **21 pruebas y 87 aserciones aprobadas** en la ultima validacion documentada.
- Se compilaron correctamente los recursos frontend con Vite.

### 8. Alcance actual y mejoras futuras

El proyecto es un prototipo funcional de demostracion, no una infraestructura de produccion escalada. La base SQLite sirve para pruebas y uso local; si se ejecuta en varios servidores o con muchas salas simultaneas, convendria migrar a PostgreSQL y Redis y revisar la estrategia de escalado y concurrencia.

Otras mejoras posibles son agregar salas persistentes con ciclo de vida administrado, mejores indicadores de conexion, pruebas en navegadores/dispositivos variados y despliegue en un servidor estable. Ngrok es una herramienta de tunel para desarrollo y demostraciones; el hostname gratuito puede variar o mostrar una advertencia al visitante.

### 9. Guion breve para exponer

> “El proyecto es un juego web multijugador de accion cenital, inspirado en Boxhead, desarrollado sobre Laravel. Tiene un lobby con chat, salas privadas con codigo y QR, y una arena sincronizada en tiempo real con Laravel Reverb y Echo. El servidor valida las acciones y administra enemigos, salud, puntuacion y oleadas.
>
> Uno de los principales desafios fue permitir que jugadores de otras computadoras accedieran no solo a la pagina, sino tambien a los WebSockets. Al principio se necesitaban dos tuneles, pero Ngrok gratuito no permitia crear el segundo con un subdominio personalizado. Lo resolvimos agregando un gateway en Node.js: Ngrok expone un solo puerto y el gateway separa el trafico web del trafico de Reverb.
>
> Tambien corregimos una referencia local de Vite que impedia cargar los estilos desde otro equipo, configuramos por separado las URL de Laravel y Reverb y mejoramos los mensajes ante errores de red. Finalmente, confirmamos el funcionamiento entre dos computadoras y pasamos las pruebas automatizadas y la compilacion del frontend.”

## Resumen para diapositivas

1. **Problema y objetivo:** juego web multijugador accesible desde distintos equipos.
2. **Tecnologias:** Laravel, Reverb/Echo, JavaScript/Canvas, SQLite, Node.js y Ngrok.
3. **Funciones:** lobby, chat, salas privadas, QR, controles multiplataforma y supervivencia por oleadas.
4. **Desafio de red:** dos servicios locales (web y WebSocket) y restricciones del plan gratuito de Ngrok.
5. **Solucion:** gateway Node.js en un puerto y un unico tunel publico.
6. **Resultados:** juego confirmado entre dos computadoras, pruebas automatizadas y build correctos.
7. **Siguiente etapa:** persistencia y escalado para produccion, monitoreo y despliegue estable.
