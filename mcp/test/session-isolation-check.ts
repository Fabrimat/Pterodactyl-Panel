import test from 'node:test';
import assert from 'node:assert/strict';
import type { AddressInfo } from 'node:net';
import { createHttpApp } from '../src/http.js';
import { fakeToken, stubPanelFetch, accountResponse, initializeRequestBody, JSON_RPC_HEADERS } from './fake-panel.js';

const PANEL_URL = 'https://panel.example.test';
process.env.PANEL_URL = PANEL_URL;

function listen(app: ReturnType<typeof createHttpApp>['app']) {
    const server = app.listen(0);
    const { port } = server.address() as AddressInfo;
    return { server, baseUrl: `http://127.0.0.1:${port}` };
}

async function initialize(baseUrl: string, token: string): Promise<{ sessionId: string; toolCount: number }> {
    const initRes = await fetch(`${baseUrl}/mcp`, {
        method: 'POST',
        headers: { ...JSON_RPC_HEADERS, Authorization: `Bearer ${token}` },
        body: JSON.stringify(initializeRequestBody()),
    });
    const initText = await initRes.text();
    assert.equal(initRes.status, 200, initText);
    const sessionId = initRes.headers.get('mcp-session-id');
    assert.ok(sessionId, 'expected a Mcp-Session-Id response header');

    const listRes = await fetch(`${baseUrl}/mcp`, {
        method: 'POST',
        headers: { ...JSON_RPC_HEADERS, Authorization: `Bearer ${token}`, 'mcp-session-id': sessionId! },
        body: JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }),
    });
    const listText = await listRes.text();
    assert.equal(listRes.status, 200, listText);
    const body = JSON.parse(listText) as { result: { tools: unknown[] } };

    return { sessionId: sessionId!, toolCount: body.result.tools.length };
}

test('two concurrent sessions with different identities get different tool sets, with isolated tokens', async (t) => {
    const adminToken = fakeToken(['client:read', 'client:write', 'admin:read', 'admin:write']);
    const userToken = fakeToken(['client:read', 'client:write']);

    t.mock.method(
        globalThis,
        'fetch',
        stubPanelFetch(PANEL_URL, (url, init) => {
            const headers = (init?.headers ?? {}) as Record<string, string>;
            const isAdmin = headers.Authorization === `Bearer ${adminToken}`;
            assert.match(url, /\/api\/client\/account$/);
            return accountResponse(isAdmin);
        })
    );

    const { app, sessions, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const [admin, user] = await Promise.all([initialize(baseUrl, adminToken), initialize(baseUrl, userToken)]);

        // Admin identity + all four scopes -> every tool (application + client rows).
        // Plain identity + client-only scopes -> only the client rows.
        assert.notEqual(admin.toolCount, user.toolCount);
        assert.ok(admin.toolCount > user.toolCount);

        // Each session's own entry carries exactly its own token, and only that token.
        const adminEntry = sessions.get(admin.sessionId);
        const userEntry = sessions.get(user.sessionId);
        assert.equal(adminEntry?.token, adminToken);
        assert.equal(userEntry?.token, userToken);
        assert.notEqual(adminEntry?.token, userEntry?.token);

        // Tearing down one session removes only that session's entry and token.
        const del = await fetch(`${baseUrl}/mcp`, {
            method: 'DELETE',
            headers: { ...JSON_RPC_HEADERS, Authorization: `Bearer ${adminToken}`, 'mcp-session-id': admin.sessionId },
        });
        assert.ok(del.ok);
        assert.ok(!sessions.has(admin.sessionId));
        assert.ok(sessions.has(user.sessionId));
        assert.equal(sessions.get(user.sessionId)?.token, userToken);
    } finally {
        stop();
        server.close();
    }
});

test('a request with a different token cannot ride an existing session id', async (t) => {
    const adminToken = fakeToken(['client:read', 'client:write', 'admin:read', 'admin:write']);
    // Self-fabricated: never went through startSession, never resolved against the
    // panel. Well-formed enough to pass local decode, which is exactly the point.
    const foreignToken = fakeToken(['client:read']);

    t.mock.method(
        globalThis,
        'fetch',
        stubPanelFetch(PANEL_URL, () => accountResponse(true))
    );

    const { app, sessions, stop } = createHttpApp({ panelUrl: PANEL_URL, publicUrl: 'http://127.0.0.1:0/mcp' });
    const { server, baseUrl } = listen(app);

    try {
        const admin = await initialize(baseUrl, adminToken);

        const res = await fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers: { ...JSON_RPC_HEADERS, Authorization: `Bearer ${foreignToken}`, 'mcp-session-id': admin.sessionId },
            body: JSON.stringify({ jsonrpc: '2.0', id: 3, method: 'tools/list', params: {} }),
        });

        assert.equal(res.status, 401);
        assert.match(res.headers.get('www-authenticate') ?? '', /resource_metadata="/);
        const text = await res.text();
        assert.ok(!text.includes('tools'), 'the admin tool list must not have been served to a mismatched token');

        // The session itself is untouched: the admin's own token still works on it.
        assert.equal(sessions.get(admin.sessionId)?.token, adminToken);
    } finally {
        stop();
        server.close();
    }
});

test('an idle session is swept and its token discarded without an explicit DELETE', async (t) => {
    const token = fakeToken(['client:read']);

    t.mock.method(
        globalThis,
        'fetch',
        stubPanelFetch(PANEL_URL, () => accountResponse(false))
    );

    const { app, sessions, stop } = createHttpApp({
        panelUrl: PANEL_URL,
        publicUrl: 'http://127.0.0.1:0/mcp',
        sessionIdleMs: 20,
        sweepIntervalMs: 10,
    });
    const { server, baseUrl } = listen(app);

    try {
        const { sessionId } = await initialize(baseUrl, token);
        assert.ok(sessions.has(sessionId));

        await new Promise((resolve) => setTimeout(resolve, 100));

        assert.ok(!sessions.has(sessionId), 'expected the idle session to have been swept');
    } finally {
        stop();
        server.close();
    }
});
