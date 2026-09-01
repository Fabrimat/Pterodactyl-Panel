import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { endpoints, type EndpointRow } from './endpoints.js';
import { request } from './client.js';

const applicationKey = process.env.PANEL_APPLICATION_KEY;
const clientKey = process.env.PANEL_CLIENT_KEY;
const readOnly = process.env.PANEL_MCP_READ_ONLY === '1';

if (!applicationKey && !clientKey) {
    console.error(
        'panel-mcp: set PANEL_APPLICATION_KEY and/or PANEL_CLIENT_KEY (see .env.example). No tools can be registered without at least one.'
    );
    process.exit(1);
}

if (!process.env.PANEL_URL) {
    console.error('panel-mcp: PANEL_URL is not set.');
    process.exit(1);
}

const server = new McpServer({ name: 'pterodactyl-panel-mcp', version: '0.1.0' });

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

function makeHandler(row: EndpointRow) {
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
        });

        return formatResult(result);
    };
}

let registered = 0;
for (const row of endpoints) {
    const hasKey = row.api === 'application' ? !!applicationKey : !!clientKey;
    if (!hasKey) {
        continue;
    }

    // Read-only mode: skip everything but plain GETs.
    if (readOnly && row.method !== 'GET') {
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
        makeHandler(row)
    );
    registered += 1;
}

if (registered === 0) {
    console.error('panel-mcp: no tools were registered (read-only mode with no GET rows for the configured key?).');
    process.exit(1);
}

const transport = new StdioServerTransport();
await server.connect(transport);
console.error(`panel-mcp: ready, ${registered} tools registered.`);
