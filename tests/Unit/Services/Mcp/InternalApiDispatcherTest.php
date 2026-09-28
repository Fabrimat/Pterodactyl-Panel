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
     * An array or an object is not a value a text body can represent at all. Coercing it
     * to '' the way a missing argument is coerced would still let build() hand dispatch()
     * a request with an empty body, and for the file write endpoint that truncates
     * whatever file the caller asked to update while still reporting success. That is the
     * one failure here that destroys data silently, and it has to be refused before a
     * request is ever assembled rather than answered with one.
     */
    public function testTextRowRefusesANonScalarValueInsteadOfWritingAnEmptyFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');

        $this->dispatcher->build($this->row(), [
            'serverId' => 'abcd1234',
            'content' => ['not' => 'a string'],
        ], $this->outer());
    }

    /**
     * call() does not catch this itself. Which JSON-RPC error code belongs on a bad
     * argument is a decision the controller makes, not this class: an isError result
     * describes a call the Panel actually received and refused, and this call never
     * reached the Panel at all, so it has to propagate rather than be reported as one.
     */
    public function testCallPropagatesTheSameExceptionBuildThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');

        $this->dispatcher->call($this->row(), [
            'serverId' => 'abcd1234',
            'content' => ['not' => 'a string'],
        ], $this->outer());
    }

    /**
     * An absent argument and an explicit null are indistinguishable in intent, both
     * meaning "the caller did not supply content", and writing an empty file is a
     * legitimate thing a caller can want on purpose. Neither of them is refused the way a
     * genuine array or object is.
     */
    public function testTextRowTreatsAnExplicitNullTheSameAsAnEmptyString(): void
    {
        $request = $this->dispatcher->build($this->row(), [
            'serverId' => 'abcd1234',
            'content' => null,
        ], $this->outer());

        $this->assertSame('', $request->getContent());
    }

    /**
     * A path parameter is a value the caller chose. A "/" is refused outright by
     * build() rather than being encoded and left for the router to decide what it
     * matches, see testPathParametersContainingASlashAreRefused() below. Anything
     * else still goes through rawurlencode() so it cannot be mistaken for a second
     * query string or an extra path segment.
     */
    public function testPathParametersAreEncoded(): void
    {
        $request = $this->dispatcher->build($this->row(), [
            'serverId' => 'users?x=1',
            'content' => '',
        ], $this->outer());

        $this->assertSame('/api/client/servers/users%3Fx%3D1/files/write', $request->getPathInfo());
    }

    /**
     * Every path parameter in the endpoint table names a single resource by id, so a
     * "/" is refused rather than encoded: Illuminate\Routing\Matching\UriValidator::
     * matches() decodes the whole path before it ever matches a route, so an encoded
     * slash would still be seen by the router as a real path separator.
     */
    public function testPathParametersContainingASlashAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('may not contain "/"');

        $this->dispatcher->build($this->row(), [
            'serverId' => '../../application/users?x=1',
            'content' => '',
        ], $this->outer());
    }

    /**
     * An array or an object for a path parameter used to be coerced to '', which still
     * produced a request the Panel would answer 404 to, but for the wrong reason: the
     * caller would read "not found" when what actually happened is "wrong argument
     * shape", with no way to tell the two apart from a 404 alone. Refused here the same
     * way a non-scalar text body is, so it reads the same way too.
     */
    public function testPathParametersRefuseANonScalarValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');

        $this->dispatcher->build($this->row(), [
            'serverId' => ['not' => 'a string'],
            'content' => '',
        ], $this->outer());
    }

    /**
     * An absent argument and an explicit null are not the same failure as a non-scalar
     * value: there is no legitimate resource id either way, but there is also no sharper
     * answer available than the 404 an empty path segment already produces, so neither
     * is refused. This is unchanged from before the check above was added.
     */
    public function testPathParametersTreatAnAbsentOrNullValueAsAnEmptyString(): void
    {
        $absent = $this->dispatcher->build($this->row(), [
            'content' => '',
        ], $this->outer());

        $null = $this->dispatcher->build($this->row(), [
            'serverId' => null,
            'content' => '',
        ], $this->outer());

        $this->assertSame('/api/client/servers//files/write', $absent->getPathInfo());
        $this->assertSame('/api/client/servers//files/write', $null->getPathInfo());
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
