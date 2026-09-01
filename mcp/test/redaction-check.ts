// Not picked up by the repo-root Jest config on purpose: no __tests__ directory, no
// .test./.spec. suffix. Run via `npm test` from mcp/, which uses node's test runner.
import test from 'node:test';
import assert from 'node:assert/strict';
import { request } from '../src/client.js';

const SECRET = 'ptla_should_never_leak_this_value';

function stringifyResult(result: unknown): string {
    return JSON.stringify(result);
}

test('missing API key never touches the network and does not leak anything', async () => {
    delete process.env.PANEL_APPLICATION_KEY;
    process.env.PANEL_URL = 'https://panel.example.com';

    const result = await request({ api: 'application', method: 'GET', path: '/users' });

    assert.equal(result.ok, false);
    assert.ok(!stringifyResult(result).includes(SECRET));
});

test('a thrown error object exposing an Authorization header is never stringified as a whole', async (t) => {
    process.env.PANEL_APPLICATION_KEY = 'ptla_configured_key_for_this_test';
    process.env.PANEL_URL = 'https://panel.example.com';

    // Shaped like a real axios/fetch error: the useful message plus a request/config
    // object carrying the very header we must never leak.
    const fakeError = Object.assign(new Error('request failed with status code 422'), {
        config: { headers: { Authorization: `Bearer ${SECRET}` } },
        request: { path: '/api/application/users' },
    });

    t.mock.method(globalThis, 'fetch', async () => {
        throw fakeError;
    });

    const result = await request({ api: 'application', method: 'GET', path: '/users' });

    assert.equal(result.ok, false);
    const serialized = stringifyResult(result);
    assert.ok(!serialized.includes(SECRET), 'Authorization header leaked into the tool result');
    assert.ok(!serialized.includes('Authorization'));
});

test('an HTTP error response surfaces status and the panel error body, nothing else', async (t) => {
    process.env.PANEL_APPLICATION_KEY = 'ptla_configured_key_for_this_test';
    process.env.PANEL_URL = 'https://panel.example.com';

    t.mock.method(globalThis, 'fetch', async () =>
        new Response(JSON.stringify({ errors: [{ code: 'ValidationException', status: '422', detail: 'The name field is required.' }] }), {
            status: 422,
            headers: { 'Content-Type': 'application/json' },
        })
    );

    const result = await request({ api: 'application', method: 'POST', path: '/users', jsonBody: { foo: 'bar' } });

    assert.equal(result.ok, false);
    if (!result.ok) {
        assert.equal(result.status, 422);
        assert.deepEqual(result.errors, [{ code: 'ValidationException', status: '422', detail: 'The name field is required.' }]);
    }
    assert.ok(!stringifyResult(result).includes(SECRET));
});

test('a successful JSON response is passed through', async (t) => {
    process.env.PANEL_CLIENT_KEY = 'ptlc_configured_key_for_this_test';
    process.env.PANEL_URL = 'https://panel.example.com';

    t.mock.method(globalThis, 'fetch', async () =>
        new Response(JSON.stringify({ object: 'user', attributes: { id: 1 } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        })
    );

    const result = await request({ api: 'client', method: 'GET', path: '/' });

    assert.equal(result.ok, true);
    if (result.ok) {
        assert.deepEqual(result.body, { object: 'user', attributes: { id: 1 } });
    }
});

test('a text response type is returned as a raw string', async (t) => {
    process.env.PANEL_CLIENT_KEY = 'ptlc_configured_key_for_this_test';
    process.env.PANEL_URL = 'https://panel.example.com';

    t.mock.method(globalThis, 'fetch', async () => new Response('hello world', { status: 200 }));

    const result = await request({
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/files/contents',
        pathParams: { serverId: 'abc-123' },
        query: { file: '/server.properties' },
        responseType: 'text',
    });

    assert.equal(result.ok, true);
    if (result.ok) {
        assert.equal(result.body, 'hello world');
    }
});
