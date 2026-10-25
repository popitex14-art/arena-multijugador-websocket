import http from 'node:http';
import { isIP } from 'node:net';
import httpProxy from 'http-proxy';

const gatewayHost = process.env.GATEWAY_HOST ?? '127.0.0.1';
const gatewayPort = Number(process.env.GATEWAY_PORT ?? 9000);
const webTarget = process.env.WEB_TARGET ?? 'http://127.0.0.1:8000';
const reverbTarget = process.env.REVERB_TARGET ?? 'http://127.0.0.1:8080';

if (!Number.isInteger(gatewayPort) || gatewayPort < 1 || gatewayPort > 65535) {
    throw new Error('GATEWAY_PORT must be a valid TCP port.');
}

const proxy = httpProxy.createProxyServer({
    changeOrigin: false,
    xfwd: false,
    ws: true,
});

function isReverbPath(requestUrl = '/') {
    const pathname = new URL(requestUrl, 'http://gateway.local').pathname;

    return pathname === '/app' || pathname.startsWith('/app/');
}

function normalizedForwardedFor(request) {
    const forwardedFor = request.headers['x-forwarded-for'];
    const addresses = typeof forwardedFor === 'string'
        ? forwardedFor.split(',').map((address) => address.trim()).filter((address) => isIP(address))
        : [];

    if (addresses.length > 0) {
        return addresses.join(', ');
    }

    return request.socket.remoteAddress ?? '';
}

function normalizeForwardedHeaders(proxyRequest, request) {
    const forwardedProto = request.headers['x-forwarded-proto'];
    const requestedScheme = typeof forwardedProto === 'string'
        ? forwardedProto.split(',')[0].trim().toLowerCase()
        : '';
    const scheme = requestedScheme === 'https' ? 'https' : 'http';
    const forwardedHost = request.headers.host;

    if (forwardedHost) {
        proxyRequest.setHeader('X-Forwarded-Host', forwardedHost);
    }

    proxyRequest.setHeader('X-Forwarded-Proto', scheme);
    proxyRequest.setHeader('X-Forwarded-Port', scheme === 'https' ? '443' : '80');
    proxyRequest.setHeader('X-Forwarded-For', normalizedForwardedFor(request));
    proxyRequest.removeHeader('Forwarded');
}

proxy.on('proxyReq', normalizeForwardedHeaders);
proxy.on('proxyReqWs', normalizeForwardedHeaders);
proxy.on('error', (error, _request, responseOrSocket) => {
    console.error(`Gateway proxy error: ${error.message}`);

    if (responseOrSocket && typeof responseOrSocket.writeHead === 'function') {
        if (!responseOrSocket.headersSent) {
            responseOrSocket.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
        }

        responseOrSocket.end('Gateway upstream is unavailable.');

        return;
    }

    responseOrSocket?.destroy();
});

const server = http.createServer((request, response) => {
    const target = isReverbPath(request.url) ? reverbTarget : webTarget;

    proxy.web(request, response, { target });
});

server.on('upgrade', (request, socket, head) => {
    proxy.ws(request, socket, head, { target: reverbTarget });
});

server.on('error', (error) => {
    console.error(`Gateway server error: ${error.message}`);
    process.exitCode = 1;
});

server.listen(gatewayPort, gatewayHost, () => {
    console.log(`Gateway listening on http://${gatewayHost}:${gatewayPort}`);
    console.log(`HTTP requests -> ${webTarget}`);
    console.log(`WebSocket and /app/* requests -> ${reverbTarget}`);
});

function shutDown() {
    server.close(() => {
        proxy.close();
        process.exit(0);
    });
}

process.once('SIGINT', shutDown);
process.once('SIGTERM', shutDown);
