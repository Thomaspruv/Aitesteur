// Tests for installPrivateNetworkGuard(). Two kinds:
//   - A mock-context unit test of the response-based backstop (the mechanism
//     that closes the HTTP-redirect gap — see shared.mjs's comment on why
//     context.route() alone can't catch it). Deterministic, no browser.
//   - Real-Playwright integration tests of the route()/routeWebSocket()
//     blocking against actual local servers. Slower, but this is the part
//     that's easy to get subtly wrong by only reasoning about the API.

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import net from 'node:net';
import { chromium } from 'playwright';
import { installPrivateNetworkGuard } from './shared.mjs';

describe('installPrivateNetworkGuard: response-based backstop (mocked context)', () => {
    function fakeContext() {
        const handlers = {};
        return {
            handlers,
            closeCalled: false,
            on(event, handler) {
                handlers[event] = handler;
            },
            async routeWebSocket() {},
            async route() {},
            async close() {
                this.closeCalled = true;
            },
        };
    }

    test('closes the context when a response comes back from a private address', async () => {
        const context = fakeContext();
        const guardState = await installPrivateNetworkGuard(context);

        assert.ok(context.handlers.response, 'a response listener should have been registered');

        context.handlers.response({ serverAddr: async () => ({ ipAddress: '169.254.169.254', port: 80 }) });

        // The handler resolves serverAddr() asynchronously (a .then chain) —
        // give it a tick to settle before asserting.
        await new Promise((resolve) => setImmediate(resolve));

        assert.strictEqual(guardState.blockedAddress, '169.254.169.254');
        assert.strictEqual(context.closeCalled, true);
    });

    test('does not close the context for a response from a public address', async () => {
        const context = fakeContext();
        const guardState = await installPrivateNetworkGuard(context);

        context.handlers.response({ serverAddr: async () => ({ ipAddress: '8.8.8.8', port: 443 }) });
        await new Promise((resolve) => setImmediate(resolve));

        assert.strictEqual(guardState.blockedAddress, null);
        assert.strictEqual(context.closeCalled, false);
    });

    test('does not throw when serverAddr() resolves to null (e.g. a mocked/data response)', async () => {
        const context = fakeContext();
        await installPrivateNetworkGuard(context);

        context.handlers.response({ serverAddr: async () => null });
        await new Promise((resolve) => setImmediate(resolve));

        assert.strictEqual(context.closeCalled, false);
    });
});

describe('installPrivateNetworkGuard: real browser (route + WebSocket blocking)', () => {
    test('blocks direct navigation to a private-network server', async () => {
        const server = net.createServer((socket) => socket.end());
        const port = await new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));

        const browser = await chromium.launch({ headless: true });
        try {
            const context = await browser.newContext();
            await installPrivateNetworkGuard(context);
            const page = await context.newPage();

            await assert.rejects(() => page.goto(`http://127.0.0.1:${port}/`, { timeout: 5000 }));
        } finally {
            await browser.close();
            server.close();
        }
    });

    test('allows navigation to a public site through', async () => {
        const browser = await chromium.launch({ headless: true });
        try {
            const context = await browser.newContext();
            await installPrivateNetworkGuard(context);
            const page = await context.newPage();

            const response = await page.goto('https://example.com/', { waitUntil: 'domcontentloaded', timeout: 15000 });
            assert.strictEqual(response.status(), 200);
        } finally {
            await browser.close();
        }
    });

    test('blocks a WebSocket connection to a private-network target', async () => {
        let tcpConnectionSeen = false;
        const tcpServer = net.createServer((socket) => {
            tcpConnectionSeen = true;
            socket.destroy();
        });
        const wsPort = await new Promise((resolve) => tcpServer.listen(0, '127.0.0.1', () => resolve(tcpServer.address().port)));

        const browser = await chromium.launch({ headless: true });
        try {
            const context = await browser.newContext();
            await installPrivateNetworkGuard(context);
            const page = await context.newPage();
            await page.goto('about:blank');

            await page.evaluate(
                (port) =>
                    new Promise((resolve) => {
                        const ws = new WebSocket(`ws://127.0.0.1:${port}`);
                        ws.onopen = ws.onerror = ws.onclose = () => resolve();
                        setTimeout(resolve, 1500);
                    }),
                wsPort
            );

            // The point isn't what the page-side WebSocket object reports (a
            // routed-but-unconnected WebSocket is mocked and looks "open" to
            // the page by design — see WebSocketRoute's docs) — it's whether
            // any real TCP connection ever reached the target.
            assert.strictEqual(tcpConnectionSeen, false);
        } finally {
            await browser.close();
            tcpServer.close();
        }
    });
});
