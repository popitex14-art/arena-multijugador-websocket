const statusLabels = {
    connected: 'Tiempo real conectado',
    connecting: 'Conectando con tiempo real...',
    reconnecting: 'Reconectando al servidor...',
    disconnected: 'Sin conexión en tiempo real',
    failed: 'No se pudo conectar con tiempo real',
};

const connectionStatusElements = document.querySelectorAll('[data-connection-status]');

function updateConnectionStatus(connectionState) {
    const label = statusLabels[connectionState] ?? 'Estado de conexion desconocido';

    connectionStatusElements.forEach((element) => {
        element.textContent = label;
        element.dataset.connectionState = statusLabels[connectionState] ? connectionState : 'unknown';
    });
}

if (window.Echo && connectionStatusElements.length > 0) {
    updateConnectionStatus(window.Echo.connector.connectionStatus());
    window.Echo.connector.onConnectionChange(updateConnectionStatus);
} else {
    updateConnectionStatus('failed');
}
