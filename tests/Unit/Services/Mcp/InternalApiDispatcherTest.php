<?php

namespace Pterodactyl\Tests\Unit\Services\Mcp;

use Illuminate\Http\Request;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Services\Mcp\InternalApiDispatcher;

class InternalApiDispatcherTest extends TestCase
{
    protected InternalApiDispatcher $dispatcher;

    public function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = new InternalApiDispatcher();
    }

    /**
     * The file write endpoint reads the body of the request with getContent(), so the
     * contents being written have to arrive as the body and not as an input field. Sending
     * them through the parameter bag would write an empty file over the target and still
     * report success, which is the one failure here that destroys data silently.
     */
    public function testTextRowSendsTheRawStringAsTheRequestBody(): void
    {
        $contents = "  first line  \n\n[section]\nkey = value\n";

        $request = $this->dispatcher->build($this->row(), [
            'serverId' => '1a7ce997-259b-452e-8b4e-cecc464142ca',
            'file' => '/home/container/server.properties',
            'content' => $contents,
        ], $this->outer());

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame($contents, $request->getContent());
        $this->assertSame('text/plain', $request->headers->get('Content-Type'));
        $this->assertSame('/api/client/servers/1a7ce997-259b-452e-8b4e-cecc464142ca/files/write', $request->getPathInfo());
        $this->assertSame('/home/container/server.properties', $request->query->get('file'));
    }

    /**
     * A path parameter is a value the caller chose, so it may not be able to decide which
     * route the internal request matches.
     */
    public function testPathParametersAreEncoded(): void
    {
        $request = $this->dispatcher->build($this->row(), [
            'serverId' => '../../application/users?x=1',
            'content' => '',
        ], $this->outer());

        $this->assertSame('/api/client/servers/..%2F..%2Fapplication%2Fusers%3Fx%3D1/files/write', $request->getPathInfo());
        $this->assertStringStartsWith('/api/client/servers/', $request->getPathInfo());
    }

    /**
     * Only the header that authenticates the caller is carried over. Anything else, a
     * session cookie in particular, would be a second way to authenticate the internal
     * request that the caller never asked for.
     */
    public function testOnlyTheAuthorizationHeaderIsForwarded(): void
    {
        $request = $this->dispatcher->build($this->row(), ['serverId' => 'abcd1234', 'content' => ''], $this->outer());

        $this->assertSame('Bearer ptlc_example', $request->headers->get('Authorization'));
        $this->assertSame('application/json', $request->headers->get('Accept'));
        $this->assertNull($request->headers->get('Cookie'));
        $this->assertNull($request->headers->get('Mcp-Session-Id'));
        // The address of the caller, not the loopback address Request::create() defaults
        // to, so that an API key restricted to a set of addresses still works.
        $this->assertSame('203.0.113.9', $request->ip());
    }

    /**
     * Query and body values are taken from the row rather than from the arguments, so an
     * argument that the row does not describe is not passed on to the Panel.
     */
    public function testOnlyTheKeysDescribedByTheRowAreSent(): void
    {
        $row = [
            'name' => 'panel_admin_users_create',
            'api' => 'application',
            'method' => 'POST',
            'path' => '/users',
            'query' => ['include' => ['type' => 'string'], 'filter' => ['type' => 'object']],
            'body' => ['username' => ['type' => 'string'], 'email' => ['type' => 'string']],
        ];

        $request = $this->dispatcher->build($row, [
            'username' => 'someone',
            'email' => 'someone@example.com',
            'root_admin' => true,
            'include' => 'servers',
            'filter' => ['email' => 'someone@example.com'],
        ], $this->outer());

        $this->assertSame('/api/application/users', $request->getPathInfo());
        $this->assertSame('application/json', $request->headers->get('Content-Type'));
        $this->assertSame('{"username":"someone","email":"someone@example.com"}', $request->getContent());
        $this->assertSame('servers', $request->query->get('include'));
        // The nested map has to reach the Panel as filter[email]=value, which is the only
        // form the list endpoints accept.
        $this->assertSame(['email' => 'someone@example.com'], $request->query->all()['filter']);
    }

    /**
     * The row for writing the contents of a file, which is the one that exercises every
     * channel at once: a path parameter, a query parameter and a raw body.
     *
     * @return array<string, mixed>
     */
    protected function row(): array
    {
        return [
            'name' => 'panel_client_servers_files_write',
            'api' => 'client',
            'method' => 'POST',
            'path' => '/servers/{serverId}/files/write',
            'path_params' => ['serverId' => ['type' => 'string']],
            'query' => ['file' => ['type' => 'string']],
            'body_type' => 'text',
            'text_field' => 'content',
        ];
    }

    protected function outer(): Request
    {
        return Request::create('https://panel.example.com/mcp', 'POST', [], ['pterodactyl_session' => 'abc'], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ptlc_example',
            'HTTP_MCP_SESSION_ID' => 'e6f6c1f4',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.9',
        ], '{"jsonrpc":"2.0"}');
    }
}
