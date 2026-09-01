// Shared tool-registration logic used by both the stdio entry point (src/index.ts)
// and the Streamable HTTP entry point (src/http.ts), so the two transports can never
// drift apart on how a row in endpoints.ts becomes an MCP tool.

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { endpoints, type ApiName, type EndpointRow } from './endpoints.js';
import { request } from './client.js';

export interface RegisterOptions {
    /** Which of the two APIs to consider at all. */
    apis: ReadonlySet<ApiName>;
    /** Skip every non-GET row. */
    readOnly?: boolean;
    /**
     * Bearer token forwarded on every call made by a registered tool. Omit for the
     * stdio transport, which falls back to the env-configured PANEL_APPLICATION_KEY /
     * PANEL_CLIENT_KEY (see client.ts).
     */
    token?: string;
    /**
     * Extra per-row gate, evaluated after the api/readOnly checks above. Used by the
     * HTTP transport to intersect a session's tools with the bearer token's granted
     * OAuth scopes. A row for which this returns false is not registered at all.
     */
    filter?: (row: EndpointRow) => boolean;
}

function formatResult(result: Awaited<ReturnType<typeof request>>): CallToolResult {
    if (!result.ok) {
        const payload = { status: result.status, errors: result.errors };
        return { isError: true, content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }] };
    }

    if (result.body === null) {
        return { content: [{ type: 'text', text: 'OK (no content)' }] };
    }

    const text = typeof result.body === 'string' ? result.body : JSON.stringify(result.body, null, 2);
    return { content: [{ type: 'text', text }] };
}

function makeHandler(row: EndpointRow, token: string | undefined) {
    return async (args: Record<string, unknown>): Promise<CallToolResult> => {
        const pathParams: Record<string, string | number> = {};
        for (const key of Object.keys(row.pathParams ?? {})) {
            pathParams[key] = args[key] as string | number;
        }

        const query: Record<string, unknown> = {};
        for (const key of Object.keys(row.query ?? {})) {
            if (args[key] !== undefined) {
                query[key] = args[key];
            }
        }

        let jsonBody: unknown;
        let textBody: string | undefined;
        if (row.bodyType === 'text') {
            textBody = String(args[row.textField ?? 'content'] ?? '');
        } else if (row.body) {
            const body: Record<string, unknown> = {};
            for (const key of Object.keys(row.body)) {
                if (args[key] !== undefined) {
                    body[key] = args[key];
                }
            }
            jsonBody = body;
        }

        const result = await request({
            api: row.api,
            method: row.method,
            path: row.path,
            pathParams,
            query,
            jsonBody,
            textBody,
            responseType: row.responseType,
            token,
        });

        return formatResult(result);
    };
}

/**
 * Registers one MCP tool per matching row in endpoints.ts against the given server.
 * Returns the number of tools registered.
 */
export function registerEndpointTools(server: McpServer, options: RegisterOptions): number {
    let registered = 0;
    for (const row of endpoints) {
        if (!options.apis.has(row.api)) {
            continue;
        }

        // Read-only mode: skip everything but plain GETs.
        if (options.readOnly && row.method !== 'GET') {
            continue;
        }

        if (options.filter && !options.filter(row)) {
            continue;
        }

        const inputSchema: z.ZodRawShape = {
            ...(row.pathParams ?? {}),
            ...(row.query ?? {}),
            ...(row.bodyType === 'text' ? { [row.textField ?? 'content']: z.string() } : row.body ?? {}),
        };

        // The MCP spec defaults destructiveHint to true when absent, so a non-GET tool must
        // either carry true or omit the key - never assert false, which would tell a
        // hint-gating client 'no confirmation needed' on a tool that was merely never flagged.
        // Every non-GET row is therefore treated as destructive; destructive: true in
        // endpoints.ts documents why a specific row is dangerous, it does not gate this.
        const annotations =
            row.method === 'GET' ? { readOnlyHint: true } : { readOnlyHint: false, destructiveHint: true };

        server.registerTool(
            row.name,
            {
                description: row.description,
                inputSchema,
                annotations,
            },
            makeHandler(row, options.token)
        );
        registered += 1;
    }

    return registered;
}
