<?php

namespace Pterodactyl\Services\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\Http\Kernel as HttpKernel;

class InternalApiDispatcher
{
    /**
     * Slashes and unicode are left alone so that file paths and server names in a result
     * read the way an operator wrote them instead of as escape sequences.
     */
    protected const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Runs a single row of the endpoint table against the REST API of the Panel and
     * returns the MCP CallToolResult describing what came back.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function call(array $row, array $arguments, Request $request): array
    {
        return $this->result($row, $this->dispatch($this->build($row, $arguments, $request)));
    }

    /**
     * Builds the internal request for a row. Public so that the shape of the request a row
     * produces can be asserted without standing up the whole HTTP kernel.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     */
    public function build(array $row, array $arguments, Request $request): Request
    {
        $path = EndpointRegistry::basePath($row) . $this->path($row, $arguments);

        // http_build_query is what turns the nested "filter" map into filter[key]=value,
        // which is the form every list endpoint on the Panel expects.
        $query = http_build_query(array_filter(
            array_intersect_key($arguments, $row['query'] ?? []),
            fn ($value) => $value !== null && $value !== ''
        ));

        [$content, $contentType] = $this->body($row, $arguments);

        $server = array_filter([
            // The Authorization header is copied verbatim and it has to stay that way.
            // Sanctum and Passport build their guards around the request bound in the
            // container and memoize the user they resolve, and RequestGuard::setRequest()
            // does not clear that user, so an internal request carrying any other bearer
            // token could still be answered as the user the outer request authenticated
            // as. Forward the header of the caller or forward none at all.
            'HTTP_AUTHORIZATION' => $request->headers->get('Authorization'),
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => $request->headers->get('User-Agent'),
            'CONTENT_TYPE' => $contentType,
            // Request::create() would otherwise report the request as coming from
            // 127.0.0.1, which an API key with an IP allowlist would be refused for and
            // which the activity log would record as the address of the actor. Use the
            // address already resolved for the outer request so that whatever TrustProxies
            // worked out on the way in is carried through.
            'REMOTE_ADDR' => $request->ip(),
        ], fn ($value) => $value !== null);

        return Request::create(
            $request->getSchemeAndHttpHost() . $path . ($query === '' ? '' : '?' . $query),
            strtoupper((string) ($row['method'] ?? 'GET')),
            [],
            [],
            [],
            $server,
            $content
        );
    }

    /**
     * Substitutes the {placeholders} in the path of a row with the arguments of the call.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     */
    protected function path(array $row, array $arguments): string
    {
        $path = (string) ($row['path'] ?? '');

        foreach (array_keys($row['path_params'] ?? []) as $key) {
            $value = $arguments[$key] ?? '';

            // Encoded because several of these are strings the caller chose. A value
            // holding a slash or a question mark would otherwise decide which route the
            // request below matches, rather than the tool that was called deciding it.
            $path = str_replace('{' . $key . '}', rawurlencode(is_scalar($value) ? (string) $value : ''), $path);
        }

        return $path;
    }

    /**
     * The body of the internal request and the content type it is sent with.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function body(array $row, array $arguments): array
    {
        if (($row['body_type'] ?? null) === 'text') {
            $value = $arguments[$row['text_field'] ?? 'content'] ?? '';

            // This has to be the raw body of the request rather than an input field: the
            // file write endpoint reads it back with getContent(), so routing it through
            // the parameter bag instead would write an empty file over the one the caller
            // asked to update and still report success.
            return [is_scalar($value) ? (string) $value : '', 'text/plain'];
        }

        if (empty($row['body'])) {
            return [null, null];
        }

        // Encoded as a JSON document for the same reason: Laravel only reads input out of
        // the JSON bag when the request declares itself as JSON, which is what real API
        // traffic against these endpoints looks like.
        return [(string) json_encode((object) array_intersect_key($arguments, $row['body'])), 'application/json'];
    }

    /**
     * Runs the request through the HTTP kernel. Everything an API request normally passes
     * through, from authentication to the permission checks to validation, runs here
     * exactly as it would for the same request arriving over the network. That is the
     * entire point of going through the kernel rather than re-implementing any of it.
     */
    protected function dispatch(Request $request): Response
    {
        $kernel = app(HttpKernel::class);
        $original = app('request');

        try {
            // Nothing catches around this beyond the restore below. The kernel renders its
            // own exceptions, so a 403 from a permission check arrives here as an ordinary
            // response, and forwarding that to the caller is exactly what we want.
            return $kernel->handle($request);
        } finally {
            // Mandatory. Kernel::handle() rebinds the "request" instance in the container
            // and drops the cached copy held by the facade, for the request it was handed.
            // Without putting both back, everything that runs after this point in the outer
            // request, from the rest of its middleware to the rate limiter to a second tool
            // call in the same request, would be reading a request that has already
            // finished.
            //
            // Router::$currentRequest, and therefore Route::current(), is deliberately left
            // pointing at the internal route. Nothing on the way back out of the outer
            // request consults it, and the outer request resolves its own route through the
            // resolver stored on the request object itself.
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * Maps the response the Panel produced onto an MCP CallToolResult.
     *
     * Only the status and the response body of the Panel are ever read here. No exception
     * message, no header and nothing off the request object may reach a caller: the
     * request carries the bearer token of the caller, and a tool result is the one thing
     * on this path that is handed back verbatim.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function result(array $row, Response $response): array
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();

        if ($status < 200 || $status >= 300) {
            // A refused call is a successful tool result that reports the refusal, not a
            // JSON-RPC error: the protocol call itself worked, the Panel simply said no,
            // and a model needs to see that answer rather than a transport level failure.
            return $this->content(json_encode([
                'status' => $status,
                'errors' => $this->errors($content),
            ], self::JSON_FLAGS), true);
        }

        if (trim($content) === '') {
            return $this->content('OK (no content)');
        }

        // Rows flagged as text responses are the ones that do not answer with JSON at all,
        // such as reading the contents of a file, so they are passed straight through.
        if (($row['response_type'] ?? null) === 'text') {
            return $this->content($content);
        }

        $decoded = json_decode($content, true);

        return $this->content(
            json_last_error() === JSON_ERROR_NONE ? json_encode($decoded, self::JSON_FLAGS) : $content
        );
    }

    /**
     * The "errors" member of an error response from the Panel, or something equivalent
     * when the response was not the JSON document the API always answers with.
     */
    protected function errors(string $content): mixed
    {
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [['detail' => 'The Panel returned a response that was not valid JSON.']];
        }

        return is_array($decoded) && array_key_exists('errors', $decoded) ? $decoded['errors'] : $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function content(string|false $text, bool $error = false): array
    {
        $result = ['content' => [['type' => 'text', 'text' => (string) $text]]];

        return $error ? array_merge(['isError' => true], $result) : $result;
    }
}
