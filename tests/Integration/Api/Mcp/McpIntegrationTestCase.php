<?php

namespace Pterodactyl\Tests\Integration\Api\Mcp;

use Pterodactyl\Models\User;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Tests\Integration\Api\OAuth\OAuthIntegrationTestCase;

abstract class McpIntegrationTestCase extends OAuthIntegrationTestCase
{
    /**
     * Posts a single JSON-RPC 2.0 request to the MCP endpoint.
     *
     * @param array<string, mixed> $payload
     */
    protected function rpc(array $payload): TestResponse
    {
        return $this->postJson('/mcp', $payload);
    }

    /**
     * Posts a raw body to the MCP endpoint. postJson() always encodes its payload as
     * valid JSON, which cannot exercise the parse error path, so this sends whatever
     * string it is given exactly as provided.
     */
    protected function rawPost(string $content, array $headers = []): TestResponse
    {
        $headers = array_merge(['Content-Type' => 'application/json'], $headers);

        return $this->call('POST', '/mcp', [], [], [], $this->transformHeadersToServerVars($headers), $content);
    }

    /**
     * Calls a tool by name and returns the raw HTTP response, for tests that need to
     * inspect the JSON-RPC envelope itself rather than just the result.
     *
     * @param array<string, mixed> $arguments
     */
    protected function callTool(string $name, array $arguments = [], mixed $id = 1): TestResponse
    {
        return $this->rpc([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    /**
     * Calls a tool and returns the decoded "result" member of the response. A refused
     * tool call still comes back as a result carrying isError, so this only fails the
     * test when the response is a JSON-RPC error, i.e. something the gate in
     * ToolCatalog would never produce for a well-formed call.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function toolResult(string $name, array $arguments = [], mixed $id = 1): array
    {
        $decoded = $this->callTool($name, $arguments, $id)->assertOk()->json();

        $this->assertArrayNotHasKey(
            'error',
            $decoded,
            'Expected a tool result, got a JSON-RPC error instead: ' . json_encode($decoded['error'] ?? null)
        );

        return $decoded['result'];
    }

    /**
     * Decodes the text content of a CallToolResult as JSON, falling back to the raw
     * string for the handful of tools that answer with something else.
     *
     * @param array<string, mixed> $result
     */
    protected function decodeContent(array $result): mixed
    {
        $text = $result['content'][0]['text'] ?? null;
        $decoded = json_decode((string) $text, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
    }

    /**
     * The tool descriptors visible to whichever caller is currently authenticated.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function listTools(): array
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();

        return $response->json('result.tools');
    }

    /**
     * @return string[]
     */
    protected function toolNames(): array
    {
        return array_column($this->listTools(), 'name');
    }

    /**
     * Authenticates as an OAuth access token, same as the parent implementation, but
     * also attaches a bearer header to the request.
     *
     * AddOAuthChallenge, unique to this endpoint, turns away any request that does not
     * present a bearer token before the real guard stack ever runs. Passport::actingAs()
     * fakes the "oauth" guard directly and never sends a real Authorization header, so
     * without one every OAuth authenticated request made against /mcp would be refused
     * before authentication is even attempted.
     */
    protected function actingAsOAuthUser(User $user, array $scopes = []): User
    {
        parent::actingAsOAuthUser($user, $scopes);

        $this->withHeader('Authorization', 'Bearer oauth-test-token');

        return $user;
    }
}
