<?php

namespace Pterodactyl\Http\Controllers\Mcp;

use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\Mcp\ToolCatalog;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Mcp\InternalApiDispatcher;

class McpController extends Controller
{
    /**
     * The revision of the Model Context Protocol this endpoint implements.
     *
     * Exactly one revision is advertised on purpose. This server answers a single response
     * on the POST that asked for it, has no event stream and no batching, and 2025-06-18 is
     * the revision that describes that: the earlier ones require a server to accept
     * JSON-RPC batches, which claiming support for and then refusing would be a lie.
     */
    public const PROTOCOL_VERSION = '2025-06-18';

    protected const SUPPORTED_PROTOCOL_VERSIONS = [self::PROTOCOL_VERSION];

    public function __construct(
        private ToolCatalog $catalog,
        private InternalApiDispatcher $dispatcher,
    ) {
    }

    /**
     * Handles a single JSON-RPC 2.0 request.
     *
     * The MCP-Protocol-Version header a client sends on requests after the handshake is
     * accepted and ignored: there is only one revision to negotiate, so there is nothing
     * for it to select.
     */
    public function handle(Request $request): Response|JsonResponse
    {
        // Read from the raw body rather than through the input bag. The middleware that
        // trims strings and nulls out empty ones runs over parsed input, and the arguments
        // of a tool call include things like the contents of a file, which must arrive
        // exactly as the caller sent them.
        $payload = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error(null, -32700, 'The request body could not be parsed as JSON.', Response::HTTP_BAD_REQUEST);
        }

        // Batching was removed from the protocol in the revision above, so an array at the
        // top level is a client talking a version of the protocol this endpoint does not
        // implement rather than something worth trying to answer.
        // An empty object decodes to an empty PHP array, which array_is_list() reports as a
        // list, so it is excluded here and left to the membership check below to reject with
        // a message that describes what is actually wrong with it.
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return $this->error(null, -32600, 'The request body must be a single JSON-RPC 2.0 request object. Batched requests are not supported by protocol revision ' . self::PROTOCOL_VERSION . '.', Response::HTTP_BAD_REQUEST);
        }

        $method = $payload['method'] ?? null;
        $id = $payload['id'] ?? null;

        // A request without an id is a notification. Nothing may ever be sent back for one,
        // not a result and not an error, so it is acknowledged and dropped here before any
        // of it is acted on.
        if (!array_key_exists('id', $payload)) {
            return response()->noContent(Response::HTTP_ACCEPTED);
        }

        if (($payload['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '') {
            return $this->error($id, -32600, 'A request must carry a "jsonrpc" member of "2.0" and a "method" name.', Response::HTTP_BAD_REQUEST);
        }

        // json_decode() with associative arrays turns both a JSON object and a JSON array
        // into a PHP array, so rejecting a non-empty list is what actually holds the caller
        // to the object the message asks for.
        $params = $payload['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return $this->error($id, -32602, 'The "params" member must be an object.');
        }

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->tools($request)]),
            'tools/call' => $this->callTool($request, $id, $params),
            default => $this->error($id, -32601, sprintf('The method "%s" is not implemented by this server.', $method)),
        };
    }

    /**
     * The Streamable HTTP transport uses GET to open a server sent event stream and DELETE
     * to end a session. Neither exists here: every response is returned on the POST that
     * asked for it and there is no session state to end. The protocol allows a server that
     * offers no stream to refuse both, and answering with the methods that are allowed
     * tells a client so immediately instead of leaving it waiting on a stream.
     *
     * Deliberately not authenticated. Which methods an endpoint accepts is not a secret,
     * and a client should not have to hold a valid token to be told it is asking wrongly.
     */
    public function unsupportedMethod(): Response
    {
        return response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requested
                : self::PROTOCOL_VERSION,
            // The tool list is fixed for the lifetime of a request, and it is rebuilt from
            // the account and token on every one, so there is never a change to notify.
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'pterodactyl-panel', 'version' => (string) config('app.version')],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function tools(Request $request): array
    {
        $user = $this->caller($request);

        return $this->catalog->descriptors($user, $user->currentAccessToken());
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function callTool(Request $request, mixed $id, array $params): JsonResponse
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (!is_string($name) || $name === '') {
            return $this->error($id, -32602, 'A tool name is required to call a tool.');
        }

        if (!is_array($arguments)) {
            return $this->error($id, -32602, 'The "arguments" member must be an object.');
        }

        // Resolved through the same filtering the listing uses. A client is not trusted to
        // only call back what it was given: this is the gate, the listing is a convenience.
        $user = $this->caller($request);
        $row = $this->catalog->find($name, $user, $user->currentAccessToken());

        if ($row === null) {
            return $this->error($id, -32602, $this->catalog->exists($name)
                ? sprintf('The tool "%s" is not available to this account or access token.', $name)
                : sprintf('There is no tool named "%s".', $name));
        }

        // A tool that the Panel refuses comes back from the dispatcher as a result carrying
        // isError, not as a JSON-RPC error. The call itself worked; the answer was no.
        return $this->result($id, $this->dispatcher->call($row, $arguments, $request));
    }

    /**
     * The account behind this request. Together with the token it presented this is what
     * decides which tools it may see and call.
     */
    protected function caller(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function result(mixed $id, mixed $result): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * A JSON-RPC error is only for a failure of the protocol itself. Everything that goes
     * wrong inside a tool is reported as a successful result carrying isError, so that a
     * model reads the answer of the Panel rather than a transport level failure.
     */
    protected function error(mixed $id, int $code, string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
