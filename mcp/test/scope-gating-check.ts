import test, { type TestContext } from 'node:test';
import assert from 'node:assert/strict';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { endpoints, type ApiName, type EndpointRow } from '../src/endpoints.js';
import { registerEndpointTools } from '../src/tool-registry.js';
import { scopeAllows } from '../src/oauth.js';

function registeredNames(t: TestContext, apis: ApiName[], scopes: string[]): string[] {
    const server = new McpServer({ name: 'test', version: '0.0.0' });
    const registerToolMock = t.mock.method(server, 'registerTool');
    const scopeSet = new Set(scopes);

    registerEndpointTools(server, {
        apis: new Set(apis),
        filter: (row) => scopeAllows(row, scopeSet),
    });

    return registerToolMock.mock.calls.map((call) => call.arguments[0] as string).sort();
}

function namesFor(predicate: (row: EndpointRow) => boolean): string[] {
    return endpoints
        .filter(predicate)
        .map((row) => row.name)
        .sort();
}

test('client:read registers exactly the client GET tools', (t) => {
    const got = registeredNames(t, ['client'], ['client:read']);
    const expected = namesFor((r) => r.api === 'client' && r.method === 'GET');
    assert.deepEqual(got, expected);
});

test('client:write registers exactly the client non-GET tools', (t) => {
    const got = registeredNames(t, ['client'], ['client:write']);
    const expected = namesFor((r) => r.api === 'client' && r.method !== 'GET');
    assert.deepEqual(got, expected);
});

test('admin:read registers exactly the admin GET tools', (t) => {
    const got = registeredNames(t, ['application'], ['admin:read']);
    const expected = namesFor((r) => r.api === 'application' && r.method === 'GET');
    assert.deepEqual(got, expected);
});

test('admin:write registers exactly the admin non-GET tools', (t) => {
    const got = registeredNames(t, ['application'], ['admin:write']);
    const expected = namesFor((r) => r.api === 'application' && r.method !== 'GET');
    assert.deepEqual(got, expected);
});

test('a token with all four scopes and admin identity gets every tool', (t) => {
    const got = registeredNames(t, ['application', 'client'], [
        'client:read',
        'client:write',
        'admin:read',
        'admin:write',
    ]);
    assert.equal(got.length, endpoints.length);
});

test('a missing scope registers nothing from that subset, even when the api is otherwise in play', (t) => {
    // Admin identity (both apis in play) but the token only carries client scopes -
    // no admin:* tool should appear at all.
    const got = registeredNames(t, ['application', 'client'], ['client:read', 'client:write']);
    assert.ok(got.every((name) => name.startsWith('panel_client_')));
    assert.equal(got.length, namesFor((r) => r.api === 'client').length);
});

test('no scopes at all registers nothing', (t) => {
    const got = registeredNames(t, ['application', 'client'], []);
    assert.deepEqual(got, []);
});
