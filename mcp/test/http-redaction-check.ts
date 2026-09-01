// Extends redaction-check.ts's guarantee (nothing but a status code and the panel's
// JSON body ever leaves client.ts) to the HTTP transport: no response this server
// sends, in any of these paths, may contain a bearer token or an Authorization header.

import test from 'node:test';
import assert from 'node:assert/strict';
import type { AddressInfo } from 'node:net';
import { createHttpApp } from '../src/http.js';
import { fakeToken, stubPanelFetch, initializeRequestBody, JSON_RPC_HEADERS } from './fake-panel.js';

const PANEL_URL = 'https://panel.example.test';
process.env.PANEL_URL = PANEL_URL;

function listen(app: ReturnType<typeof createHttpApp>['app']) {
    const server = app.listen(0);
    const { port } = server.address() as AddressInfo;
    return { server, baseUrl: `http://127.0.0.1:${port}` };
}

test('a malformed bearer token never appears in the 401 response body or headers', async () => {
    const secretLookingToken = 'should-never-be-echoed-back-anywhere';
    const { app, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const res = await fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers: { ...JSON_RPC_HEADERS, Authorization: `Bearer ${secretLookingToken}` },
            body: JSON.stringify(initializeRequestBody()),
        });

        assert.equal(res.status, 401);
        const text = await res.text();
        assert.ok(!text.includes(secretLookingToken));
        const headerDump = JSON.stringify([...res.headers.entries()]);
        assert.ok(!headerDump.includes(secretLookingToken));
    } finally {
        stop();
        server.close();
    }
});

test('a thrown network error exposing an Authorization header never reaches the HTTP response', async (t) => {
    const token = fakeToken(['client:read']);

    t.mock.method(
        globalThis,
        'fetch',
        stubPanelFetch(PANEL_URL, async () => {
            // Shaped like a real axios/fetch error: carries the Authorization header on
            // a nested property, exactly like redaction-check.ts's equivalent case.
            throw Object.assign(new Error('request failed'), {
                config: { headers: { Authorization: `Bearer ${token}` } },
            });
        })
    );

    const { app, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const res = await fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers: { ...JSON_RPC_HEADERS, Authorization: `Bearer ${token}` },
            body: JSON.stringify(initializeRequestBody()),
        });

        const text = await res.text();
        assert.ok(!text.includes(token), 'bearer token leaked into the HTTP response body');
        assert.ok(!text.includes('Authorization'), 'Authorization header name leaked into the HTTP response body');
    } finally {
        stop();
        server.close();
    }
});

test('an upstream 401 from the panel propagates as 401 with a challenge, without leaking the token', async (t) => {
    const token = fakeToken(['client:read']);

    t.mock.method(
        globalThis,
        'fetch',
        stubPanelFetch(
            PANEL_URL,
            async () =>
                new Response(JSON.stringify({ errors: [{ code: 'Unauthenticated', status: '401' }] }), {
                    status: 401,
                    headers: { 'Content-Type': 'application/json' },
                })
        )
    );

    const { app, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const res = await fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers: { ...JSON_RPC_HEADERS, Authorization: `Bearer ${token}` },
            body: JSON.stringify(initializeRequestBody()),
        });

        assert.equal(res.status, 401);
        assert.match(res.headers.get('www-authenticate') ?? '', /resource_metadata="/);
        const text = await res.text();
        assert.ok(!text.includes(token));
    } finally {
        stop();
        server.close();
    }
});
