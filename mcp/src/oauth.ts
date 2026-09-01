// OAuth 2.1 resource-server support for the Streamable HTTP transport. The panel
// (Laravel Passport) is the authorization server; this file only describes that
// server for discovery and reads the token it issues. It never talks to the
// panel's /oauth/authorize or /oauth/token endpoints itself - that flow is entirely
// between the MCP client/host and the panel.

import { InvalidTokenError } from '@modelcontextprotocol/sdk/server/auth/errors.js';
import type { AuthInfo } from '@modelcontextprotocol/sdk/server/auth/types.js';
import type { OAuthTokenVerifier } from '@modelcontextprotocol/sdk/server/auth/provider.js';
import type { OAuthMetadata } from '@modelcontextprotocol/sdk/shared/auth.js';
import type { EndpointRow } from './endpoints.js';

export const OAUTH_SCOPES = ['client:read', 'client:write', 'admin:read', 'admin:write'] as const;
export type OAuthScope = (typeof OAUTH_SCOPES)[number];

/**
 * Authorization-server metadata for the panel, assuming Passport's default route
 * prefix (/oauth/authorize, /oauth/token). There is no discovery document to read
 * this from - Passport does not publish RFC 8414 metadata - so this is the one place
 * to update if the panel-side OAuth work lands under a different prefix.
 */
export function panelOAuthMetadata(panelUrl: string): OAuthMetadata {
    const issuer = panelUrl.replace(/\/+$/, '');
    return {
        issuer,
        authorization_endpoint: `${issuer}/oauth/authorize`,
        token_endpoint: `${issuer}/oauth/token`,
        response_types_supported: ['code'],
        grant_types_supported: ['authorization_code', 'refresh_token'],
        code_challenge_methods_supported: ['S256'],
        scopes_supported: [...OAUTH_SCOPES],
    };
}

/** Which of the four scopes a given endpoints.ts row requires. */
export function scopeAllows(row: EndpointRow, scopes: ReadonlySet<string>): boolean {
    if (row.api === 'client') {
        return scopes.has(row.method === 'GET' ? 'client:read' : 'client:write');
    }
    return scopes.has(row.method === 'GET' ? 'admin:read' : 'admin:write');
}

function decodeJwtPayload(token: string): Record<string, unknown> {
    const parts = token.split('.');
    if (parts.length !== 3) {
        throw new InvalidTokenError('Malformed bearer token.');
    }

    try {
        const json = Buffer.from(parts[1], 'base64url').toString('utf8');
        const payload: unknown = JSON.parse(json);
        if (typeof payload !== 'object' || payload === null) {
            throw new Error('token payload is not an object');
        }
        return payload as Record<string, unknown>;
    } catch {
        throw new InvalidTokenError('Malformed bearer token.');
    }
}

function scopesFromClaim(payload: Record<string, unknown>): string[] {
    const claim = payload.scopes ?? payload.scope;
    if (Array.isArray(claim)) {
        return claim.filter((entry): entry is string => typeof entry === 'string');
    }
    if (typeof claim === 'string') {
        return claim.split(' ').filter(Boolean);
    }
    return [];
}

/**
 * Verifies the shape of a bearer token for the requireBearerAuth middleware.
 *
 * Passport signs these tokens with the panel's own key. This server holds neither
 * that key nor a JWKS URI - no such endpoint exists in the fixed contract - so it
 * reads claims without checking the signature. That is safe: nothing decoded here
 * grants access by itself. The token is forwarded verbatim on every panel call, and
 * the panel re-validates it there, signature included. A forged-but-well-formed
 * token can at most get extra tools listed for a session; every one of them still
 * fails against the panel with 401.
 */
export const bearerTokenVerifier: OAuthTokenVerifier = {
    async verifyAccessToken(token: string): Promise<AuthInfo> {
        const payload = decodeJwtPayload(token);
        const clientId = Array.isArray(payload.aud)
            ? String(payload.aud[0])
            : String(payload.aud ?? payload.client_id ?? 'unknown');
        const expiresAt = typeof payload.exp === 'number' ? payload.exp : undefined;

        return {
            token,
            clientId,
            scopes: scopesFromClaim(payload),
            expiresAt,
        };
    },
};
