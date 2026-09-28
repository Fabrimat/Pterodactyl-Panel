<?php

namespace Pterodactyl\Tests\Integration\Api\Mcp;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Pterodactyl\Services\Mcp\EndpointRegistry;
use Pterodactyl\Services\Mcp\InternalApiDispatcher;

class DispatchTest extends McpIntegrationTestCase
{
    public function testCallingARealReadToolReturnsThePanelsJsonPayloadAsText(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $result = $this->toolResult('panel_client_account_view');

        $this->assertArrayNotHasKey('isError', $result);
        $decoded = $this->decodeContent($result);

        $this->assertSame('user', $decoded['object']);
        $this->assertSame($user->email, $decoded['attributes']['email']);
    }

    /**
     * A refusal from the Panel (403/404/422/...) is a successful JSON-RPC call that
     * carries isError, not a JSON-RPC error member: the protocol call worked, the
     * Panel simply said no, and a model needs to read that answer rather than a
     * transport level failure. A server identifier that does not exist is used here
     * as a deterministic way to trigger one without reaching a daemon.
     */
    public function testAPanelRefusalComesBackAsASuccessfulResultCarryingIsError(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->callTool('panel_client_servers_view', ['serverId' => (string) Str::uuid()])->assertOk();
        $decoded = $response->json();

        $this->assertArrayNotHasKey('error', $decoded);
        $this->assertTrue($decoded['result']['isError']);

        $body = $this->decodeContent($decoded['result']);
        $this->assertSame(404, $body['status']);
        $this->assertNotEmpty($body['errors']);
    }

    /**
     * Handler::convertExceptionToArray() merges a "source" (the file and line an
     * exception was thrown from) and folds a stack trace into "meta" on an error entry
     * when APP_DEBUG is enabled, on top of the "code", "status" and "detail" it always
     * carries. The docblock on InternalApiDispatcher::result() promises that nothing off
     * the request object reaches a caller, and unlike an ordinary REST response an MCP
     * tool result is handed to a third party model and leaves the machine, so that promise
     * has to hold in debug mode too.
     *
     * The config() call below is belt and braces for anyone reading or running this test
     * outside CI: .env.ci sets APP_DEBUG=true, so every other test in this file already
     * runs in debug, and before this fix was already handing a stack trace back on every
     * refusal without anyone noticing.
     */
    public function testAPanelRefusalNeverCarriesAFilePathOrALineNumberOrAStackTraceInDebugMode(): void
    {
        config()->set('app.debug', true);

        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->callTool('panel_client_servers_view', ['serverId' => (string) Str::uuid()])->assertOk();
        $decoded = $response->json();

        $this->assertTrue($decoded['result']['isError']);

        $body = $this->decodeContent($decoded['result']);
        $this->assertSame(404, $body['status']);
        $this->assertNotEmpty($body['errors']);

        foreach ($body['errors'] as $error) {
            $this->assertArrayNotHasKey('source', $error);
            $this->assertArrayNotHasKey('meta', $error);
            $this->assertArrayHasKey('detail', $error);
        }
    }

    /**
     * A non-scalar value for a text body never reaches the Panel, so it cannot be
     * answered as a result carrying isError. -32602 is the same JSON-RPC error every
     * other bad-argument case in McpController::callTool() already uses for an
     * argument that does not fit what the tool asked for, and it is what makes this
     * refusal distinguishable from a Panel-side one by construction, rather than by
     * the caller having to sniff whether the text parses as JSON.
     */
    public function testANonScalarTextBodyArgumentIsAJsonRpcErrorNotAToolResult(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->callTool('panel_client_servers_files_write', [
            'serverId' => (string) Str::uuid(),
            'file' => '/home/container/server.properties',
            'content' => ['not' => 'a string'],
        ])->assertOk();

        $decoded = $response->json();

        $this->assertArrayNotHasKey('result', $decoded);
        $this->assertSame(-32602, $decoded['error']['code']);
        $this->assertStringContainsString('must be a string', $decoded['error']['message']);
    }

    /**
     * InternalApiDispatcher::dispatch() rebinds the container's "request" to the
     * internal one for the duration of the kernel call and must put the outer request
     * back afterwards, or a second tool call in the same request would be reading a
     * request that already finished.
     */
    public function testASecondToolCallInTheSameProcessStillWorksAndRestoresTheRequestBinding(): void
    {
        [$user] = $this->generateTestAccount();
        $key = $this->actingAsApiKeyUser($user);

        $outer = Request::create(rtrim(config('app.url'), '/') . '/mcp', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key->identifier . decrypt($key->token),
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->app->instance('request', $outer);

        $registry = $this->app->make(EndpointRegistry::class);
        $dispatcher = $this->app->make(InternalApiDispatcher::class);
        $row = $registry->get('panel_client_account_view');

        $first = $dispatcher->call($row, [], $outer);
        $this->assertArrayNotHasKey('isError', $first);
        $this->assertSame($outer, $this->app->make('request'));

        $second = $dispatcher->call($row, [], $outer);
        $this->assertArrayNotHasKey('isError', $second);
        $this->assertSame($outer, $this->app->make('request'));
    }

    /**
     * The server file write tool sends its "file" argument as a query parameter and
     * its "content" argument as the raw request body, because
     * FileController::write() reads the body back with getContent() rather than
     * through the parameter bag. If content ever ended up in the parameter bag
     * instead, getContent() would come back empty and the tool would silently
     * truncate the target file to zero bytes while still reporting success. There is
     * no way to reach the real Wings daemon from a test, so this asserts the shape of
     * the request InternalApiDispatcher builds rather than the response of a call.
     */
    public function testFileWriteToolSendsFileAsAQueryParameterAndContentAsTheRawBody(): void
    {
        $registry = $this->app->make(EndpointRegistry::class);
        $dispatcher = $this->app->make(InternalApiDispatcher::class);
        $row = $registry->get('panel_client_servers_files_write');

        $outer = Request::create(rtrim(config('app.url'), '/') . '/mcp', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer irrelevant-for-this-assertion',
        ]);

        $built = $dispatcher->build($row, [
            'serverId' => 'abc12345',
            'file' => '/home/container/config.yml',
            'content' => "line one\nline two",
        ], $outer);

        $this->assertSame('/home/container/config.yml', $built->query->get('file'));
        $this->assertSame("line one\nline two", $built->getContent());
        $this->assertSame([], $built->request->all(), 'The file content must never land in the parsed parameter bag.');
        $this->assertStringContainsString('/servers/abc12345/files/write', $built->getPathInfo());
    }

    /**
     * A path parameter selects a single resource by id and is refused outright if it
     * holds a "/", rather than merely encoded and left for the router to sort out.
     * Illuminate\Routing\Matching\UriValidator::matches(), which is what actually
     * matches this request against a route, decodes the whole path before it ever
     * runs preg_match() against a route's compiled regex, so an encoded slash is
     * decoded straight back before that match runs, and a route's default
     * placeholder pattern happily accepts what it decodes into as an ordinary extra
     * path segment. A serverId of "abc/websocket" on the row that reads a server
     * would, without this check, decode into a path matching the sibling
     * ".../servers/{server}/websocket" route instead of the one this tool was
     * registered for: the same permission gate still runs, since it is attached to
     * whichever route actually matched, so nothing is bypassed, but the wrong
     * endpoint answers the call. A traversal-style value is refused for the same
     * reason, since it holds slashes too.
     */
    public function testAPathParameterHoldingASlashIsRefusedRatherThanRoutedToASiblingEndpoint(): void
    {
        $registry = $this->app->make(EndpointRegistry::class);
        $dispatcher = $this->app->make(InternalApiDispatcher::class);
        $row = $registry->get('panel_client_servers_view');

        $outer = Request::create(rtrim(config('app.url'), '/') . '/mcp', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer irrelevant-for-this-assertion',
        ]);

        foreach (['abc/websocket', '../../application/users?x=1'] as $escape) {
            try {
                $dispatcher->build($row, ['serverId' => $escape], $outer);
                $this->fail(sprintf('Expected build() to refuse a serverId of "%s".', $escape));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('may not contain "/"', $e->getMessage());
            }
        }

        // Confirmed end to end too: the refusal reaches the caller as a JSON-RPC
        // error, not as a result carrying isError, because the Panel never saw this
        // call at all.
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->callTool('panel_client_servers_view', ['serverId' => 'abc/websocket'])->assertOk();
        $decoded = $response->json();

        $this->assertArrayNotHasKey('result', $decoded);
        $this->assertSame(-32602, $decoded['error']['code']);
    }
}
