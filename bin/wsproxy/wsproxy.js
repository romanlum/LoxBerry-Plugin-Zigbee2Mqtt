#!/usr/bin/env node
'use strict';

// Relays the Zigbee2MQTT frontend's WebSocket (used for live device/state
// updates) to browsers. Plain PHP cannot hold a WebSocket connection open,
// so this small standalone daemon does it instead, terminating TLS itself
// so browsers can use wss:// (avoiding HTTPS mixed-content blocking) even
// though Z2M's own frontend only speaks plain ws:// on localhost.

const fs = require('fs');
const http = require('http');
const https = require('https');
const WebSocket = require('ws');

const PORT = parseInt(process.env.Z2M_WSPROXY_PORT || '8882', 10);
const TARGET = process.env.Z2M_WSPROXY_TARGET || 'ws://127.0.0.1:8881/api';
const CERT_PATH = process.env.Z2M_WSPROXY_CERT_PATH;
const KEY_PATH = process.env.Z2M_WSPROXY_KEY_PATH;

function createServer() {
    if (CERT_PATH && KEY_PATH && fs.existsSync(CERT_PATH) && fs.existsSync(KEY_PATH)) {
        return https.createServer({
            cert: fs.readFileSync(CERT_PATH),
            key: fs.readFileSync(KEY_PATH),
        });
    }
    console.warn('zigbee2mqtt wsproxy: no TLS certificate found, falling back to plain HTTP. ' +
        'wss:// clients (e.g. a browser on an HTTPS LoxBerry page) will not be able to connect.');
    return http.createServer();
}

const server = createServer();
const wss = new WebSocket.Server({ server, path: '/api' });

wss.on('connection', (client) => {
    const upstream = new WebSocket(TARGET);
    const pending = [];
    let upstreamOpen = false;

    upstream.on('open', () => {
        upstreamOpen = true;
        while (pending.length) {
            upstream.send(pending.shift());
        }
    });

    client.on('message', (data) => {
        if (upstreamOpen) {
            upstream.send(data);
        } else {
            pending.push(data);
        }
    });

    upstream.on('message', (data) => {
        if (client.readyState === WebSocket.OPEN) {
            client.send(data);
        }
    });

    const closeBoth = () => {
        if (client.readyState === WebSocket.OPEN || client.readyState === WebSocket.CONNECTING) {
            client.close();
        }
        if (upstream.readyState === WebSocket.OPEN || upstream.readyState === WebSocket.CONNECTING) {
            upstream.close();
        }
    };

    client.on('close', closeBoth);
    client.on('error', closeBoth);
    upstream.on('close', closeBoth);
    upstream.on('error', closeBoth);
});

server.listen(PORT, () => {
    console.log(`zigbee2mqtt wsproxy listening on port ${PORT}, relaying to ${TARGET}`);
});
