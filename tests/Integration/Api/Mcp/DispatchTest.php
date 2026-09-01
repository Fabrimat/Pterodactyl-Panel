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
     * A path placeholder is filled in with rawurlencode(), so an argument holding a
     * "/" or a "?" cannot decide which route the internal request matches. Laravel's
     * router decodes the path before comparing it against a route's pattern, so an
     * encoded slash that survived would still be able to walk the request out of
     * "/api/client/servers/{serverId}" and into a different endpoint entirely, such
     * as the application API's user listing.
     */
    public function testPathPlaceholdersAreEncodedSoAnArgumentCannotChangeTheMatchedRoute(): void
    {
        $registry = $this->app->make(EndpointRegistry::class);
        $dispatcher = $this->app->make(InternalApiDispatcher::class);
        $row = $registry->get('panel_client_servers_view');

        $outer = Request::create(rtrim(config('app.url'), '/') . '/mcp', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer irrelevant-for-this-assertion',
        ]);

        $escape = '../../application/users?x=1';
        $built = $dispatcher->build($row, ['serverId' => $escape], $outer);

        $this->assertStringNotContainsString('/api/application/users', $built->getPathInfo());
        $this->assertStringContainsString(rawurlencode($escape), $built->getPathInfo());

        // Confirmed end to end too: the mangled path matches no route at all once the
        // router decodes it back for comparison, so the call 404s under the client
        // API rather than ever reaching the application API's user listing.
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $result = $this->toolResult('panel_client_servers_view', ['serverId' => $escape]);
        $body = $this->decodeContent($result);

        $this->assertTrue($result['isError']);
        $this->assertSame(404, $body['status']);
    }
}
