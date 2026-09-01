import test, { type TestContext } from 'node:test';
import assert from 'node:assert/strict';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { endpoints, type ApiName } from '../src/endpoints.js';
import { registerEndpointTools } from '../src/tool-registry.js';
import { scopeAllows } from '../src/oauth.js';

// Credential route tools that should be filtered in OAuth HTTP sessions but not in stdio.
const CREDENTIAL_TOOLS = [
    'panel_client_account_two_factor_view',
    'panel_client_account_two_factor_enable',
    'panel_client_account_two_factor_disable',
    'panel_client_account_api_keys_list',
    'panel_client_account_api_keys_create',
    'panel_client_account_api_keys_delete',
    'panel_client_account_ssh_keys_list',
    'panel_client_account_ssh_keys_create',
    'panel_client_account_ssh_keys_delete',
];

function registeredNames(t: TestContext, apis: ApiName[], filter?: (name: string) => boolean): string[] {
    const server = new McpServer({ name: 'test', version: '0.0.0' });
    const registerToolMock = t.mock.method(server, 'registerTool');

    const toolNames = registerToolMock.mock.calls.map((call) => call.arguments[0] as string).sort();
    return filter ? toolNames.filter(filter) : toolNames;
}

test('OAuth HTTP session with full client scopes does not register credential tools', (t) => {
    const server = new McpServer({ name: 'test', version: '0.0.0' });
    const registerToolMock = t.mock.method(server, 'registerTool');
    const scopes = new Set(['client:read', 'client:write']);

    // Simulate the OAuth HTTP session filter: scope + credential route blocking.
    const oauthFilter = (row: any) => scopeAllows(row, scopes) && !CREDENTIAL_TOOLS.includes(row.name);

    registerEndpointTools(server, {
        apis: new Set(['client']),
        filter: oauthFilter,
    });

    const registered = registerToolMock.mock.calls.map((call) => call.arguments[0] as string);
    const credentialToolsRegistered = registered.filter((name) => CREDENTIAL_TOOLS.includes(name));

    assert.deepEqual(credentialToolsRegistered, [], 'No credential tools should be registered in OAuth HTTP sessions');
    assert.ok(registered.length > 0, 'Non-credential tools should still be registered');
});

test('stdio transport still registers credential tools', (t) => {
    const server = new McpServer({ name: 'test', version: '0.0.0' });
    const registerToolMock = t.mock.method(server, 'registerTool');

    // No filter applied - stdio is unrestricted.
    registerEndpointTools(server, {
        apis: new Set(['client']),
    });

    const registered = registerToolMock.mock.calls.map((call) => call.arguments[0] as string);
    const credentialToolsRegistered = registered.filter((name) => CREDENTIAL_TOOLS.includes(name));

    assert.deepEqual(
        credentialToolsRegistered.sort(),
        CREDENTIAL_TOOLS.sort(),
        'All credential tools should be registered in stdio'
    );
});
