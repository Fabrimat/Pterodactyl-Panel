import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import type { ApiName } from './endpoints.js';
import { registerEndpointTools } from './tool-registry.js';

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

const apis = new Set<ApiName>();
if (applicationKey) {
    apis.add('application');
}
if (clientKey) {
    apis.add('client');
}

const registered = registerEndpointTools(server, { apis, readOnly });

if (registered === 0) {
    console.error('panel-mcp: no tools were registered (read-only mode with no GET rows for the configured key?).');
    process.exit(1);
}

const transport = new StdioServerTransport();
await server.connect(transport);
console.error(`panel-mcp: ready, ${registered} tools registered.`);
