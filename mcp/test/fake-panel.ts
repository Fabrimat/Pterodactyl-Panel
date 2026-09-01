// Shared helpers for the HTTP-transport tests. Not a test file itself - see
// redaction-check.ts's header comment for why these file names avoid __tests__/
// and the .test./.spec. suffixes the root Jest config would otherwise sweep up.

function base64url(value: object): string {
    return Buffer.from(JSON.stringify(value)).toString('base64url');
}

/** Builds an unsigned JWT shaped like a Passport access token, for local decode tests. */
export function fakeToken(scopes: string[], overrides: Record<string, unknown> = {}): string {
    const header = { alg: 'RS256', typ: 'JWT' };
    const payload = {
        aud: 'test-client-id',
        sub: '1',
        scopes,
        exp: Math.floor(Date.now() / 1000) + 3600,
        ...overrides,
    };
    return `${base64url(header)}.${base64url(payload)}.unsigned-test-signature`;
}

function urlOf(input: string | URL | Request): string {
    if (typeof input === 'string') {
        return input;
    }
    return input instanceof URL ? input.href : input.url;
}

/**
 * Replaces global fetch with one that answers requests aimed at `panelUrl` with
 * `handler`, and passes every other request (in particular, the test's own calls
 * into its local Express server) through to the real fetch implementation.
 */
export function stubPanelFetch(
    panelUrl: string,
    handler: (url: string, init: RequestInit | undefined) => Response | Promise<Response>
): typeof fetch {
    const realFetch = globalThis.fetch;
    return (async (input: Parameters<typeof fetch>[0], init?: RequestInit) => {
        const url = urlOf(input);
        if (url.startsWith(panelUrl)) {
            return handler(url, init);
        }
        return realFetch(input, init);
    }) as typeof fetch;
}

export function accountResponse(admin: boolean): Response {
    return new Response(
        JSON.stringify({ object: 'user', attributes: { id: 1, admin, username: 'test', email: 't@example.com' } }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
    );
}

export function initializeRequestBody(id = 1): Record<string, unknown> {
    return {
        jsonrpc: '2.0',
        id,
        method: 'initialize',
        params: {
            protocolVersion: '2025-06-18',
            capabilities: {},
            clientInfo: { name: 'test-client', version: '1.0.0' },
        },
    };
}

export const JSON_RPC_HEADERS = {
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
};
