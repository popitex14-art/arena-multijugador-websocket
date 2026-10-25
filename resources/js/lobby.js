import QRCode from 'qrcode';

const chatForm = document.querySelector('[data-chat-form]');
const chatMessages = document.querySelector('[data-chat-messages]');
const chatStatus = document.querySelector('[data-chat-status]');

function appendChatMessage(playerName, message, sentAt = new Date()) {
    chatMessages.querySelector('.empty-state')?.remove();

    const item = document.createElement('li');
    const heading = document.createElement('strong');
    const text = document.createElement('p');
    const time = document.createElement('time');

    heading.textContent = playerName;
    text.textContent = message;
    time.dateTime = sentAt.toISOString();
    time.textContent = sentAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    item.append(heading, text, time);
    chatMessages.append(item);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

if (chatMessages && window.Echo) {
    window.Echo.channel('global-chat').listen('.GlobalChatMessage', (event) => {
        appendChatMessage(event.playerName, event.message, new Date(event.sentAt));
    });
}

chatForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = chatForm.elements.message;
    const message = input.value.trim();

    if (!message) return;

    chatStatus.textContent = 'Enviando…';

    try {
        const response = await fetch(chatForm.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': chatForm.querySelector('[name="_token"]').value,
            },
            body: JSON.stringify({ message }),
        });

        if (!response.ok) {
            const result = await response.json();
            throw new Error(result.message ?? 'No se pudo enviar el mensaje.');
        }

        input.value = '';
        chatStatus.textContent = '';
    } catch (error) {
        chatStatus.textContent = error.message;
    }
});

document.querySelectorAll('[data-room-qr]').forEach(async (canvas) => {
    try {
        await QRCode.toCanvas(canvas, canvas.dataset.roomQr, {
            width: 192,
            margin: 2,
            color: { dark: '#101827', light: '#ffffff' },
        });
    } catch (error) {
        console.error('No se pudo generar el QR de la sala.', error);
        canvas.insertAdjacentText('afterend', 'No se pudo generar el QR. Usa el enlace de invitación.');
    }
});

document.querySelector('[data-room-join-form]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const code = event.currentTarget.elements.code.value.trim().toUpperCase();
    const baseUrl = event.currentTarget.dataset.joinUrl;
    window.location.assign(`${baseUrl}/${encodeURIComponent(code)}/join`);
});

const roomCode = document.querySelector('[data-room-code]')?.dataset.roomCode;

if (roomCode && window.Echo) {
    window.Echo.private(`game-room.${roomCode}`).listen('.RoomPlayerJoined', (event) => {
        const notice = document.querySelector('[data-room-notice]');
        notice.textContent = `${event.playerName} se unió a la sala.`;
    });
}
