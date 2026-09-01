import test from 'node:test';
import assert from 'node:assert/strict';
import type { AddressInfo } from 'node:net';
import { createHttpApp } from '../src/http.js';
import { initializeRequestBody, JSON_RPC_HEADERS } from './fake-panel.js';

const PANEL_URL = 'https://panel.example.test';

function listen(app: ReturnType<typeof createHttpApp>['app']) {
    const server = app.listen(0);
    const { port } = server.address() as AddressInfo;
    return { server, baseUrl: `http://127.0.0.1:${port}` };
}

test('oauth-protected-resource metadata is served and names the panel as the authorization server', async () => {
    const { app, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const res = await fetch(`${baseUrl}/.well-known/oauth-protected-resource/mcp`);
        assert.equal(res.status, 200);
        const body = (await res.json()) as { authorization_servers?: string[]; resource?: string };
        assert.ok(body.authorization_servers?.includes(PANEL_URL));
        assert.ok(body.resource?.includes('/mcp'));
    } finally {
        stop();
        server.close();
    }
});

test('a request with no bearer token gets 401 with a spec-shaped WWW-Authenticate challenge', async () => {
    const { app, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const res = await fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers: JSON_RPC_HEADERS,
            body: JSON.stringify(initializeRequestBody()),
        });

        assert.equal(res.status, 401);
        const challenge = res.headers.get('www-authenticate');
        assert.ok(challenge, 'expected a WWW-Authenticate header');
        assert.match(challenge!, /^Bearer /);
        assert.match(challenge!, /error="invalid_token"/);
        assert.match(challenge!, /resource_metadata="[^"]+\/\.well-known\/oauth-protected-resource\/mcp"/);
    } finally {
        stop();
        server.close();
    }
});

test('a malformed bearer token gets 401 with the same challenge shape', async () => {
    const { app, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const res = await fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers: { ...JSON_RPC_HEADERS, Authorization: 'Bearer not-a-jwt' },
            body: JSON.stringify(initializeRequestBody()),
        });

        assert.equal(res.status, 401);
        assert.match(res.headers.get('www-authenticate') ?? '', /error="invalid_token"/);
    } finally {
        stop();
        server.close();
    }
});
