// Resolves who a bearer token belongs to. Called once per HTTP session (see
// http.ts) and the result cached in that session's entry - never re-fetched or
// shared across sessions.

import { request } from './client.js';

export interface PanelIdentity {
    admin: boolean;
}

export type IdentityResult = { ok: true; identity: PanelIdentity } | { ok: false; status: number; errors: unknown };

export async function resolveIdentity(token: string): Promise<IdentityResult> {
    const result = await request({ api: 'client', method: 'GET', path: '/account', token });
    if (!result.ok) {
        return { ok: false, status: result.status, errors: result.errors };
    }

    const attributes = (result.body as { attributes?: { admin?: unknown } } | null)?.attributes;
    return { ok: true, identity: { admin: attributes?.admin === true } };
}
