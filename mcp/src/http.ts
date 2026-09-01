// Streamable HTTP entry point. Unlike src/index.ts, this transport does not read a
// shared PANEL_APPLICATION_KEY / PANEL_CLIENT_KEY from the environment - every caller
// authenticates as themselves with an OAuth bearer token, and that token is what gets
// forwarded to the panel. See README.md for the OAuth setup this expects.

import { randomUUID, timingSafeEqual } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import express, { type Express, type Request, type Response } from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { isInitializeRequest } from '@modelcontextprotocol/sdk/types.js';
import { mcpAuthMetadataRouter, getOAuthProtectedResourceMetadataUrl } from '@modelcontextprotocol/sdk/server/auth/router.js';
import { requireBearerAuth } from '@modelcontextprotocol/sdk/server/auth/middleware/bearerAuth.js';
import type { ApiName } from './endpoints.js';
import { registerEndpointTools } from './tool-registry.js';
import { OAUTH_SCOPES, bearerTokenVerifier, panelOAuthMetadata, scopeAllows } from './oauth.js';
import { resolveIdentity } from './identity.js';

const DEFAULT_IDLE_MS = 30 * 60 * 1000;
const DEFAULT_SWEEP_MS = 60 * 1000;

// Constant-time: the session id is already the harder-to-guess half, but comparing
// the token itself is cheap to do safely, so there is no reason not to.
function tokensMatch(a: string, b: string): boolean {
    const bufA = Buffer.from(a);
    const bufB = Buffer.from(b);
    return bufA.length === bufB.length && timingSafeEqual(bufA, bufB);
}

interface SessionEntry {
    server: McpServer;
    transport: StreamableHTTPServerTransport;
    token: string;
    admin: boolean;
    lastSeen: number;
}

export interface HttpAppOptions {
    /** Base URL of the panel, no trailing slash. Also used as the OAuth issuer. */
    panelUrl: string;
    /** Externally reachable URL of this server's /mcp endpoint. */
    publicUrl: string;
    readOnly?: boolean;
    sessionIdleMs?: number;
    sweepIntervalMs?: number;
}

export interface HttpApp {
    app: Express;
    /** Exposed for tests only - do not reach into session.token from outside a session's own request handling. */
    sessions: Map<string, SessionEntry>;
    stop: () => void;
}

export function createHttpApp(options: HttpAppOptions): HttpApp {
    const resourceServerUrl = new URL(options.publicUrl);
    const oauthMetadata = panelOAuthMetadata(options.panelUrl);
    const resourceMetadataUrl = getOAuthProtectedResourceMetadataUrl(resourceServerUrl);
    const sessions = new Map<string, SessionEntry>();

    const app = express();
    app.use(express.json());

    app.use(
        mcpAuthMetadataRouter({
            oauthMetadata,
            resourceServerUrl,
            scopesSupported: [...OAUTH_SCOPES],
            resourceName: 'Pterodactyl Panel',
        })
    );

    const requireAuth = requireBearerAuth({ verifier: bearerTokenVerifier, resourceMetadataUrl });

    // Starts a brand new session from an initialize request. Resolves identity via a
    // single call to the panel's own /account endpoint, then registers exactly the
    // tools this user's admin flag and this token's scopes allow - never more.
    async function startSession(req: Request, res: Response): Promise<SessionEntry | undefined> {
        const token = req.auth?.token;
        if (!token) {
            res.status(401).json({ error: 'invalid_token' });
            return undefined;
        }

        const identity = await resolveIdentity(token);
        if (!identity.ok) {
            if (identity.status === 401) {
                res.set(
                    'WWW-Authenticate',
                    `Bearer error="invalid_token", error_description="The panel rejected this token.", resource_metadata="${resourceMetadataUrl}"`
                );
            }
            // Surfaced verbatim: a forced-2FA panel rejects an unenrolled user here with
            // its own error body, and that is more useful to the caller than a generic
            // "identity check failed" message.
            res.status(identity.status || 502).json({ errors: identity.errors });
            return undefined;
        }

        const server = new McpServer({ name: 'pterodactyl-panel-mcp', version: '0.1.0' });
        const scopes = new Set(req.auth?.scopes ?? []);
        const apis = new Set<ApiName>(identity.identity.admin ? ['application', 'client'] : ['client']);

        registerEndpointTools(server, {
            apis,
            token,
            readOnly: options.readOnly,
            filter: (row) => scopeAllows(row, scopes),
        });

        // `entry` is captured by the callbacks below before it is assigned; that is
        // fine because the transport only invokes them once handleRequest actually
        // runs, by which point entry has been assigned (see below).
        let entry: SessionEntry;

        const transport = new StreamableHTTPServerTransport({
            sessionIdGenerator: () => randomUUID(),
            enableJsonResponse: true,
            onsessioninitialized: (sessionId) => {
                entry.lastSeen = Date.now();
                sessions.set(sessionId, entry);
            },
            onsessionclosed: (sessionId) => {
                sessions.delete(sessionId);
            },
        });

        entry = { server, transport, token, admin: identity.identity.admin, lastSeen: Date.now() };

        transport.onclose = () => {
            if (transport.sessionId) {
                sessions.delete(transport.sessionId);
            }
            void server.close();
        };

        await server.connect(transport);
        return entry;
    }

    async function handleMcpRequest(req: Request, res: Response): Promise<void> {
        const sessionId = req.header('mcp-session-id');

        if (sessionId) {
            const entry = sessions.get(sessionId);
            if (!entry) {
                res.status(404).json({ error: 'Unknown or expired MCP session.' });
                return;
            }
            // requireAuth above only proves *some* well-formed, unexpired bearer token
            // was presented - not that it's this session's token. Without this check,
            // a completely different (even self-fabricated) token paired with a known
            // session id would ride on the identity and scopes resolved at session
            // creation. The tools themselves keep forwarding the token cached at
            // session start regardless - there is no mid-session token swap - so this
            // is the one place that has to reject a mismatch.
            if (!req.auth?.token || !tokensMatch(req.auth.token, entry.token)) {
                res.set(
                    'WWW-Authenticate',
                    `Bearer error="invalid_token", error_description="Token does not match this session.", resource_metadata="${resourceMetadataUrl}"`
                );
                res.status(401).json({ error: 'invalid_token' });
                return;
            }
            entry.lastSeen = Date.now();
            await entry.transport.handleRequest(req, res, req.body);
            return;
        }

        if (req.method !== 'POST' || !isInitializeRequest(req.body)) {
            res.status(400).json({ error: 'Missing Mcp-Session-Id header.' });
            return;
        }

        const entry = await startSession(req, res);
        if (!entry) {
            return; // startSession already wrote the error response.
        }
        await entry.transport.handleRequest(req, res, req.body);
    }

    app.post('/mcp', requireAuth, handleMcpRequest);
    app.get('/mcp', requireAuth, handleMcpRequest);
    app.delete('/mcp', requireAuth, handleMcpRequest);

    // Idle sessions accumulate a live bearer token in memory for as long as they
    // exist; sweep them so a client that vanishes mid-conversation doesn't hold onto
    // one indefinitely.
    const sweep = setInterval(() => {
        const cutoff = Date.now() - (options.sessionIdleMs ?? DEFAULT_IDLE_MS);
        for (const [sessionId, entry] of sessions) {
            if (entry.lastSeen < cutoff) {
                sessions.delete(sessionId);
                void entry.transport.close();
            }
        }
    }, options.sweepIntervalMs ?? DEFAULT_SWEEP_MS);
    sweep.unref();

    return { app, sessions, stop: () => clearInterval(sweep) };
}

function main(): void {
    const panelUrl = process.env.PANEL_URL;
    if (!panelUrl) {
        console.error('panel-mcp: PANEL_URL is not set.');
        process.exit(1);
    }

    const port = Number(process.env.PANEL_MCP_HTTP_PORT ?? 8089);
    const host = process.env.PANEL_MCP_HTTP_HOST ?? '127.0.0.1';
    const publicUrl = process.env.PANEL_MCP_PUBLIC_URL ?? `http://${host}:${port}/mcp`;
    const readOnly = process.env.PANEL_MCP_READ_ONLY === '1';
    const sessionIdleMs = process.env.PANEL_MCP_HTTP_SESSION_IDLE_MS
        ? Number(process.env.PANEL_MCP_HTTP_SESSION_IDLE_MS)
        : undefined;

    const { app } = createHttpApp({ panelUrl, publicUrl, readOnly, sessionIdleMs });

    app.listen(port, host, () => {
        console.error(`panel-mcp: listening on http://${host}:${port}/mcp`);
    });
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    main();
}
