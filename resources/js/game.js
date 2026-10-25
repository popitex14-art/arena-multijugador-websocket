const canvas = document.querySelector('[data-game-canvas]');

if (canvas) {
    const context = canvas.getContext('2d');
    const game = document.querySelector('[data-game-room]');
    const playerId = Number(game.dataset.playerId);
    const actionUrl = game.dataset.actionUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const initialState = JSON.parse(document.querySelector('[data-initial-state]').textContent);
    const players = new Map(initialState.players.map((player) => [Number(player.id), player]));
    const enemies = new Map(initialState.enemies.map((enemy) => [Number(enemy.id), enemy]));
    const projectiles = initialState.projectiles.map((projectile) => ({ ...projectile, age: 0 }));
    const input = { moveX: 0, moveY: 0, aimX: 1, aimY: 0, firing: false };
    const pressedKeys = new Set();
    const world = { width: 960, height: 540 };
    const playerRadius = 18;
    const movementSpeed = 220;
    const weaponCooldowns = { 1: 250, 2: 800, 3: 120 };
    const playerFrames = [
        [
            '....HHHH....',
            '...HGGGGH...',
            '...GSSSSG...',
            '...GSSSSG...',
            '....GGGG....',
            '..GGGGGGGG..',
            '.GGGDDDDGGG.',
            '.GGGDDDDGGG.',
            '..GGGGGGGG..',
            '...GG..GG...',
            '...BB..BB...',
            '..BB....BB..',
        ],
        [
            '....HHHH....',
            '...HGGGGH...',
            '...GSSSSG...',
            '...GSSSSG...',
            '....GGGG....',
            '..GGGGGGGG..',
            '.GGGDDDDGGG.',
            '.GGGDDDDGGG.',
            '..GGGGGGGG..',
            '..GG....GG..',
            '..BB....BB..',
            '...BB..BB...',
        ],
    ];
    const zombieFrames = [
        [
            '....ZZZZ....',
            '...ZLLLLZ...',
            '..ZLLLLLLZ..',
            '.ZZLELLELZZ.',
            '.ZLLLLLLLLZ.',
            '.ZLZZLLZZLZ.',
            '..ZLLLLLLZ..',
            '...ZLZZLZ...',
            '..ZZZZZZZZ..',
            '.ZZ..ZZ..ZZ.',
            'ZZ...ZZ...ZZ',
            '............',
        ],
        [
            '....ZZZZ....',
            '...ZLLLLZ...',
            '..ZLLLLLLZ..',
            '.ZZLELLELZZ.',
            '.ZLLLLLLLLZ.',
            '.ZLZZLLZZLZ.',
            '..ZLLLLLLZ..',
            '...ZLZZLZ...',
            '..ZZZZZZZZ..',
            '.ZZ..ZZ..ZZ.',
            '..ZZ..ZZ..ZZ',
            '............',
        ],
    ];
    const playerPalettes = {
        self: { H: '#243a49', G: '#43b88e', S: '#f2c9a5', D: '#24745e', B: '#24313a' },
        teammate: { H: '#29344d', G: '#6f91ed', S: '#f2c9a5', D: '#455eb0', B: '#252b3b' },
    };
    const zombiePalette = { Z: '#354438', L: '#91b85d', E: '#f05c4d' };
    const floorDetails = Array.from({ length: 84 }, (_, index) => ({
        x: (index * 137 + 47) % world.width,
        y: (index * 83 + 29) % world.height,
        size: index % 5 === 0 ? 5 : 3,
        color: index % 3 === 0 ? '#263444' : '#1d2a39',
    }));
    const visualEffects = [];
    const barriers = [
        { x: 280, y: 140, width: 110, height: 22 },
        { x: 570, y: 370, width: 110, height: 22 },
        { x: 660, y: 145, width: 28, height: 95 },
        { x: 270, y: 320, width: 28, height: 95 },
    ];
    let lastFrame = performance.now();
    let lastShotAt = 0;
    let movePending = false;

    const localPlayer = players.get(playerId);
    const status = document.querySelector('[data-game-status]');
    const roster = document.querySelector('[data-player-list]');
    let gameStatus = initialState.status;
    let currentWave = Number(initialState.wave);

    function addEffect(effect) {
        visualEffects.push(effect);

        if (visualEffects.length > 120) {
            visualEffects.splice(0, visualEffects.length - 120);
        }
    }

    function spawnImpact(x, y, color, count = 7) {
        for (let index = 0; index < count; index++) {
            const angle = Math.random() * Math.PI * 2;
            const speed = 24 + Math.random() * 105;
            const lifetime = 180 + Math.random() * 220;

            addEffect({
                type: 'spark',
                x,
                y,
                velocityX: Math.cos(angle) * speed,
                velocityY: Math.sin(angle) * speed,
                color,
                size: 2 + Math.random() * 2,
                life: lifetime,
                maxLife: lifetime,
            });
        }
    }

    function spawnMuzzleFlash(projectile) {
        const angle = Number(projectile.angle);
        const owner = players.get(Number(projectile.ownerId));
        const originX = owner ? Number(owner.x) : Number(projectile.x);
        const originY = owner ? Number(owner.y) : Number(projectile.y);

        addEffect({
            type: 'flash',
            x: originX + Math.cos(angle) * 22,
            y: originY + Math.sin(angle) * 22,
            angle,
            life: 85,
            maxLife: 85,
        });
    }

    function updateRoster() {
        roster.replaceChildren();

        for (const player of players.values()) {
            const item = document.createElement('li');
            item.textContent = `${player.name}${Number(player.id) === playerId ? ' · tú' : ''} · ${player.health} HP · ${player.score} pts`;
            roster.append(item);
        }
    }

    function applySnapshot(state) {
        const previousStatus = gameStatus;
        const previousEnemies = new Map(enemies);
        gameStatus = state.status;

        for (const player of state.players) {
            const id = Number(player.id);
            const existingPlayer = players.get(id);

            if (existingPlayer) {
                const deltaX = Number(player.x) - Number(existingPlayer.x);
                const deltaY = Number(player.y) - Number(existingPlayer.y);
                existingPlayer.moving = Math.hypot(deltaX, deltaY) > 0.5;

                if (existingPlayer.moving) {
                    existingPlayer.facingAngle = Math.atan2(deltaY, deltaX);
                }

                Object.assign(existingPlayer, player);
            } else {
                players.set(id, { ...player, moving: false, facingAngle: 0 });
            }
        }

        if (Number(state.wave) === currentWave) {
            for (const enemy of state.enemies) {
                const previousEnemy = previousEnemies.get(Number(enemy.id));

                if (previousEnemy && Number(enemy.health) < Number(previousEnemy.health)) {
                    spawnImpact(Number(enemy.x), Number(enemy.y), '#ffe18a', 5);
                }
            }

            for (const [enemyId, enemy] of previousEnemies) {
                if (!state.enemies.some((updatedEnemy) => Number(updatedEnemy.id) === enemyId)) {
                    spawnImpact(Number(enemy.x), Number(enemy.y), '#f28356', 13);
                }
            }
        }

        enemies.clear();
        for (const enemy of state.enemies) {
            enemies.set(Number(enemy.id), enemy);
        }

        const previousAges = new Map(projectiles.map((projectile) => [projectile.id, projectile.age]));
        const previousProjectileIds = new Set(previousAges.keys());
        const flashedPlayers = new Set();

        for (const projectile of state.projectiles) {
            const ownerId = Number(projectile.ownerId);

            if (!previousProjectileIds.has(projectile.id) && !flashedPlayers.has(ownerId)) {
                spawnMuzzleFlash(projectile);
                flashedPlayers.add(ownerId);
            }
        }

        projectiles.splice(0, projectiles.length, ...state.projectiles.map((projectile) => ({
            ...projectile,
            age: previousAges.get(projectile.id) ?? 0,
        })));
        currentWave = Number(state.wave);

        document.querySelector('[data-wave]').textContent = state.wave;
        document.querySelector('[data-health]').textContent = localPlayer.health;
        document.querySelector('[data-score]').textContent = localPlayer.score;
        const restartForm = document.querySelector('[data-restart-form]');

        if (restartForm) {
            restartForm.hidden = state.status !== 'finished';
        }

        if (state.status === 'finished') {
            input.moveX = 0;
            input.moveY = 0;
            input.firing = false;
            status.textContent = 'Partida terminada: el equipo fue derrotado.';
        } else if (localPlayer.health === 0) {
            input.moveX = 0;
            input.moveY = 0;
            input.firing = false;
            status.textContent = 'Has sido eliminado. Tus compañeros aún pueden continuar.';
        } else if (previousStatus === 'finished' || localPlayer.health > 0) {
            status.textContent = '';
        }

        updateRoster();
    }

    function sendAction(action) {
        return fetch(actionUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(action),
        }).then(async (response) => {
            const responseBody = await response.text();
            let result;

            try {
                result = responseBody ? JSON.parse(responseBody) : {};
            } catch {
                if (!response.ok) {
                    const message = response.status === 429
                        ? 'Se alcanzó el límite de acciones. Espera un momento y vuelve a intentarlo.'
                        : `No se pudo sincronizar la acción (HTTP ${response.status}).`;

                    throw new Error(message);
                }

                throw new Error('El servidor devolvió una respuesta no válida.');
            }

            if (!response.ok) {
                const message = response.status === 401
                    ? 'Tu sesión expiró. Vuelve al lobby e ingresa de nuevo.'
                    : response.status === 429
                        ? 'Se alcanzó el límite de acciones. Espera un momento y vuelve a intentarlo.'
                        : result.message ?? `No se pudo sincronizar la acción (HTTP ${response.status}).`;

                throw new Error(message);
            }

            status.textContent = '';
            return result;
        }).catch((error) => {
            const message = error instanceof TypeError
                ? 'Se perdió la conexión con el servidor. Revisa tu conexión y vuelve a intentarlo.'
                : error.message;

            status.textContent = message;
            throw error instanceof TypeError ? new Error(message) : error;
        });
    }

    function updateAction(event) {
        if (event.state) {
            applySnapshot(event.state);
        }
    }

    if (window.Echo) {
        window.Echo.private(`game-room.${game.dataset.roomCode}`)
            .listen('.RoomGameAction', updateAction)
            .listen('.RoomPlayerJoined', (event) => {
                const existing = players.get(Number(event.playerId));
                players.set(Number(event.playerId), existing ?? {
                    id: Number(event.playerId),
                    name: event.playerName,
                    x: 480,
                    y: 270,
                    weapon: 1,
                });
                document.querySelector('[data-room-notice]').textContent = `${event.playerName} se unió a la sala.`;
                updateRoster();
            });
    } else {
        status.textContent = 'Echo no está disponible; no se puede conectar con la sala.';
    }

    function resizeCanvas() {
        const bounds = canvas.getBoundingClientRect();
        const pixelRatio = window.devicePixelRatio || 1;
        canvas.width = Math.round(bounds.width * pixelRatio);
        canvas.height = Math.round(bounds.height * pixelRatio);
        context.imageSmoothingEnabled = false;
        context.setTransform(canvas.width / world.width, 0, 0, canvas.height / world.height, 0, 0);
    }

    function drawPixelSprite(rows, palette, x, y, angle, pixelSize) {
        const width = Math.max(...rows.map((row) => row.length));
        const height = rows.length;

        context.save();
        context.translate(Math.round(x), Math.round(y));
        context.rotate(angle);

        rows.forEach((row, rowIndex) => {
            [...row].forEach((pixel, columnIndex) => {
                if (!palette[pixel]) return;

                context.fillStyle = palette[pixel];
                context.fillRect(
                    Math.round((columnIndex - width / 2) * pixelSize),
                    Math.round((rowIndex - height / 2) * pixelSize),
                    pixelSize,
                    pixelSize,
                );
            });
        });

        context.restore();
    }

    function drawWorld() {
        context.fillStyle = '#172436';
        context.fillRect(0, 0, world.width, world.height);

        context.fillStyle = '#1b2938';
        for (let y = 0; y < world.height; y += 48) {
            for (let x = 0; x < world.width; x += 48) {
                if ((x / 48 + y / 48) % 2 === 0) {
                    context.fillRect(x, y, 48, 48);
                }
            }
        }

        for (const detail of floorDetails) {
            context.fillStyle = detail.color;
            context.fillRect(detail.x, detail.y, detail.size, detail.size);
        }

        context.strokeStyle = 'rgb(255 255 255 / 4%)';
        context.lineWidth = 1;

        for (let x = 0; x <= world.width; x += 48) {
            context.beginPath();
            context.moveTo(x, 0);
            context.lineTo(x, world.height);
            context.stroke();
        }

        for (let y = 0; y <= world.height; y += 48) {
            context.beginPath();
            context.moveTo(0, y);
            context.lineTo(world.width, y);
            context.stroke();
        }

        for (const barrier of barriers) {
            context.fillStyle = 'rgb(0 0 0 / 28%)';
            context.fillRect(barrier.x + 4, barrier.y + 5, barrier.width, barrier.height);
            context.fillStyle = '#283447';
            context.fillRect(barrier.x, barrier.y, barrier.width, barrier.height);
            context.fillStyle = '#63718a';
            context.fillRect(barrier.x, barrier.y, barrier.width, 4);
            context.fillStyle = '#46556d';
            context.fillRect(barrier.x + 3, barrier.y + 5, barrier.width - 6, barrier.height - 8);
            context.fillStyle = '#8793a5';

            const isHorizontal = barrier.width > barrier.height;
            const rivetCount = isHorizontal ? 4 : 3;

            for (let rivet = 0; rivet < rivetCount; rivet++) {
                const rivetX = isHorizontal
                    ? barrier.x + 12 + rivet * ((barrier.width - 24) / (rivetCount - 1))
                    : barrier.x + barrier.width / 2;
                const rivetY = isHorizontal
                    ? barrier.y + barrier.height / 2
                    : barrier.y + 12 + rivet * ((barrier.height - 24) / (rivetCount - 1));

                context.fillRect(Math.round(rivetX), Math.round(rivetY), 3, 3);
            }
        }

        for (const enemy of enemies.values()) {
            const frame = zombieFrames[Math.floor(performance.now() / 360) % zombieFrames.length];
            drawPixelSprite(frame, zombiePalette, enemy.x, enemy.y, 0, 2.5);
            context.fillStyle = '#311c26';
            context.fillRect(enemy.x - 14, enemy.y - 20, 28, 4);
            context.fillStyle = '#ff8e77';
            context.fillRect(enemy.x - 14, enemy.y - 20, 28 * (enemy.health / enemy.maxHealth), 4);
        }
    }

    function collidesWithBarrier(x, y, radius) {
        return barriers.some((barrier) => {
            const closestX = Math.max(barrier.x, Math.min(x, barrier.x + barrier.width));
            const closestY = Math.max(barrier.y, Math.min(y, barrier.y + barrier.height));

            return Math.hypot(x - closestX, y - closestY) < radius;
        });
    }

    function drawPlayer(player, now) {
        const ownPlayer = Number(player.id) === playerId;
        const moving = ownPlayer
            ? Math.hypot(input.moveX, input.moveY) > 0.05
            : player.moving;
        const frame = moving ? Math.floor(now / 150) % playerFrames.length : 0;
        const angle = ownPlayer ? Math.atan2(input.aimY, input.aimX) : player.facingAngle ?? 0;

        context.save();
        context.translate(player.x, player.y);
        context.rotate(angle);
        context.fillStyle = '#27313a';
        context.fillRect(1, -3, 17, 6);
        context.fillStyle = '#9aa9ae';
        context.fillRect(7, -1, 10, 2);
        context.restore();

        drawPixelSprite(
            playerFrames[frame],
            ownPlayer ? playerPalettes.self : playerPalettes.teammate,
            player.x,
            player.y,
            angle,
            3,
        );

        context.fillStyle = '#18212d';
        context.fillRect(player.x - 14, player.y - 25, 28, 3);
        context.fillStyle = ownPlayer ? '#87f7d4' : '#82a0ff';
        context.fillRect(player.x - 14, player.y - 25, 28 * (player.health / 100), 3);
        context.fillStyle = '#f3f6ff';
        context.font = '12px system-ui, sans-serif';
        context.textAlign = 'center';
        context.strokeStyle = '#111827';
        context.lineWidth = 3;
        context.strokeText(player.name, player.x, player.y - 31);
        context.fillText(player.name, player.x, player.y - 31);
    }

    function drawVisualEffects(elapsed) {
        for (let index = visualEffects.length - 1; index >= 0; index--) {
            const effect = visualEffects[index];
            effect.life -= elapsed * 1000;

            if (effect.life <= 0) {
                visualEffects.splice(index, 1);
                continue;
            }

            const progress = effect.life / effect.maxLife;
            context.save();
            context.globalAlpha = progress;

            if (effect.type === 'flash') {
                context.translate(effect.x, effect.y);
                context.rotate(effect.angle);
                context.fillStyle = '#fff1a6';
                context.beginPath();
                context.moveTo(-3, -4);
                context.lineTo(15, 0);
                context.lineTo(-3, 4);
                context.lineTo(1, 0);
                context.closePath();
                context.fill();
            } else {
                effect.x += effect.velocityX * elapsed;
                effect.y += effect.velocityY * elapsed;
                effect.velocityX *= 0.94;
                effect.velocityY *= 0.94;
                context.fillStyle = effect.color;
                context.fillRect(
                    Math.round(effect.x),
                    Math.round(effect.y),
                    Math.max(1, Math.round(effect.size * progress)),
                    Math.max(1, Math.round(effect.size * progress)),
                );
            }

            context.restore();
        }
    }

    function render(now) {
        const elapsed = Math.min((now - lastFrame) / 1000, 0.05);
        lastFrame = now;
        const moveLength = Math.hypot(input.moveX, input.moveY);
        const moveScale = moveLength > 1 ? 1 / moveLength : 1;

        if (moveLength > 0) {
            const nextX = Math.max(playerRadius, Math.min(world.width - playerRadius, localPlayer.x + input.moveX * moveScale * movementSpeed * elapsed));
            const nextY = Math.max(playerRadius, Math.min(world.height - playerRadius, localPlayer.y + input.moveY * moveScale * movementSpeed * elapsed));

            if (!collidesWithBarrier(nextX, localPlayer.y, playerRadius)) localPlayer.x = nextX;
            if (!collidesWithBarrier(localPlayer.x, nextY, playerRadius)) localPlayer.y = nextY;
        }

        drawWorld();

        for (const player of players.values()) {
            drawPlayer(player, now);
        }

        for (let index = projectiles.length - 1; index >= 0; index--) {
            const projectile = projectiles[index];
            const step = elapsed * 1000;
            projectile.x += Math.cos(projectile.angle) * projectile.speed * elapsed;
            projectile.y += Math.sin(projectile.angle) * projectile.speed * elapsed;
            projectile.age += step;
            context.fillStyle = projectile.ownerId === playerId ? '#fff08a' : '#ff9a76';
            context.beginPath();
            context.arc(projectile.x, projectile.y, 4, 0, Math.PI * 2);
            context.fill();

            if (projectile.x < 0 || projectile.y < 0 || projectile.x > world.width || projectile.y > world.height || projectile.age > 2600) {
                projectiles.splice(index, 1);
            }
        }

        drawVisualEffects(elapsed);
        window.requestAnimationFrame(render);
    }

    function setAimFromPointer(event) {
        const bounds = canvas.getBoundingClientRect();
        const x = (event.clientX - bounds.left) * world.width / bounds.width;
        const y = (event.clientY - bounds.top) * world.height / bounds.height;
        input.aimX = x - localPlayer.x;
        input.aimY = y - localPlayer.y;
        const length = Math.hypot(input.aimX, input.aimY) || 1;
        input.aimX /= length;
        input.aimY /= length;
    }

    function updateKeyboardMovement() {
        input.moveX = Number(pressedKeys.has('d') || pressedKeys.has('arrowright'))
            - Number(pressedKeys.has('a') || pressedKeys.has('arrowleft'));
        input.moveY = Number(pressedKeys.has('s') || pressedKeys.has('arrowdown'))
            - Number(pressedKeys.has('w') || pressedKeys.has('arrowup'));
    }

    function fire() {
        const now = performance.now();
        const cooldown = weaponCooldowns[localPlayer.weapon] ?? 250;

        if (!input.firing || now - lastShotAt < cooldown) return;

        lastShotAt = now;
        sendAction({
            type: 'fire',
            angle: Math.atan2(input.aimY, input.aimX),
        }).catch(() => {});
    }

    function chooseWeapon(weapon) {
        if (Number(localPlayer.weapon) === weapon) return;
        localPlayer.weapon = weapon;
        document.querySelectorAll('[data-weapon]').forEach((button) => {
            button.setAttribute('aria-pressed', String(Number(button.dataset.weapon) === weapon));
        });
        sendAction({ type: 'weapon', weapon }).catch(() => {});
    }

    function bindStick(stick) {
        const thumb = stick.querySelector('[data-stick-thumb]');
        let pointerId = null;

        function updateStick(event) {
            const bounds = stick.getBoundingClientRect();
            const centerX = bounds.left + bounds.width / 2;
            const centerY = bounds.top + bounds.height / 2;
            const radius = bounds.width * 0.35;
            let x = (event.clientX - centerX) / radius;
            let y = (event.clientY - centerY) / radius;
            const length = Math.hypot(x, y);

            if (length > 1) {
                x /= length;
                y /= length;
            }

            thumb.style.transform = `translate(${x * radius}px, ${y * radius}px)`;

            if (stick.dataset.stick === 'move') {
                input.moveX = x;
                input.moveY = y;
            } else if (length > 0.15) {
                input.aimX = x;
                input.aimY = y;
            }
        }

        function releaseStick(event) {
            if (pointerId !== event.pointerId) return;

            pointerId = null;
            thumb.style.transform = '';

            if (stick.dataset.stick === 'move') {
                input.moveX = 0;
                input.moveY = 0;
            }
        }

        stick.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            pointerId = event.pointerId;
            stick.setPointerCapture(pointerId);
            updateStick(event);
        });
        stick.addEventListener('pointermove', (event) => {
            if (pointerId === event.pointerId) updateStick(event);
        });
        stick.addEventListener('pointerup', releaseStick);
        stick.addEventListener('pointercancel', releaseStick);
    }

    canvas.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'mouse') setAimFromPointer(event);
    });
    canvas.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse' && event.button === 0) {
            setAimFromPointer(event);
            input.firing = true;
        }
    });
    window.addEventListener('pointerup', (event) => {
        if (event.pointerType === 'mouse') input.firing = false;
    });
    window.addEventListener('keydown', (event) => {
        const key = event.key.toLowerCase();

        if (['arrowup', 'arrowdown', 'arrowleft', 'arrowright', ' '].includes(key)) event.preventDefault();

        if (['w', 'a', 's', 'd', 'arrowup', 'arrowdown', 'arrowleft', 'arrowright'].includes(key)) {
            pressedKeys.add(key);
            updateKeyboardMovement();
        }
        if (key === ' ') input.firing = true;
        if (['1', '2', '3'].includes(event.key)) chooseWeapon(Number(event.key));
    });
    window.addEventListener('keyup', (event) => {
        const key = event.key.toLowerCase();

        if (pressedKeys.delete(key)) updateKeyboardMovement();
        if (key === ' ') input.firing = false;
    });
    window.addEventListener('blur', () => {
        pressedKeys.clear();
        input.moveX = 0;
        input.moveY = 0;
        input.firing = false;
    });

    document.querySelectorAll('[data-stick]').forEach(bindStick);
    document.querySelectorAll('[data-weapon]').forEach((button) => {
        button.addEventListener('click', () => chooseWeapon(Number(button.dataset.weapon)));
    });
    document.querySelector('[data-fire-button]').addEventListener('pointerdown', (event) => {
        event.preventDefault();
        input.firing = true;
        event.currentTarget.setPointerCapture(event.pointerId);
    });
    document.querySelector('[data-fire-button]').addEventListener('pointerup', () => {
        input.firing = false;
    });
    document.querySelector('[data-fire-button]').addEventListener('pointercancel', () => {
        input.firing = false;
    });

    window.setInterval(() => {
        if (gameStatus !== 'active' || localPlayer.health === 0) return;

        fire();

    if (movePending) return;
        movePending = true;
    const moving = Math.hypot(input.moveX, input.moveY) >= 0.05;
    sendAction(moving ? {
        type: 'move',
        dx: input.moveX,
        dy: input.moveY,
    } : { type: 'heartbeat' }).catch(() => {}).finally(() => {
        movePending = false;
    });
    }, 100);

    applySnapshot(initialState);
    document.querySelectorAll('[data-weapon]').forEach((button) => {
        button.setAttribute('aria-pressed', String(Number(button.dataset.weapon) === Number(localPlayer.weapon)));
    });
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
    window.requestAnimationFrame(render);
}
