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
     * Throws when an argument cannot be turned into a valid request for this row at
     * all, such as a non-scalar value for a text body or a path parameter holding a
     * "/". The Panel never sees a call refused that way, so the caller of this method
     * reports it as a bad argument rather than as a result carrying isError, which is
     * reserved for a call the Panel did receive and refused.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public function call(array $row, array $arguments, Request $request): array
    {
        return $this->result($row, $this->dispatch($this->build($row, $arguments, $request)));
    }

    /**
     * Builds the internal request for a row. Public so that the shape of the request a row
     * produces can be asserted without standing up the whole HTTP kernel.
     *
     * Throws, propagated from path() or body(), when an argument cannot be turned into
     * part of a valid request at all.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     *
     * @throws \InvalidArgumentException
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
     * Throws when a path parameter value is not a scalar, or holds a "/".
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     *
     * @throws \InvalidArgumentException
     */
    protected function path(array $row, array $arguments): string
    {
        $path = (string) ($row['path'] ?? '');

        foreach (array_keys($row['path_params'] ?? []) as $key) {
            $value = $arguments[$key] ?? '';

            // An absent argument and an explicit null both fall through to an empty
            // string here, the same as a text body's content does: neither one is a
            // caller handing this a value of the wrong shape, there is simply nothing
            // to substitute, and the empty path segment that produces is answered with
            // an ordinary 404 rather than landing on some other tool's route. That is
            // not the same as saying an empty resource id is ever legitimate the way an
            // empty file body is; it just means there is no sharper failure available
            // for "nothing was supplied" than the 404 this already produces, so nothing
            // is gained by refusing it outright. An array or an object is different,
            // and that is exactly what the check below exists for: coercing it to '' the
            // way this used to would still reach the Panel and still 404, but for the
            // wrong reason. The caller would read "not found" when what actually
            // happened is "wrong argument shape", the same misleading failure F5 refuses
            // for a text body, and it has no way to tell the two apart from a 404 alone.
            if (!is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf('The "%s" argument must be a string, %s given. It selects a single resource by id, not a structured value.', $key, get_debug_type($value)));
            }

            $value = (string) $value;

            // Every path parameter in this table names a single resource by id, never
            // something with structure of its own, so a "/" has no legitimate reason to
            // appear in one, and it is refused outright here rather than merely encoded.
            // Encoding alone does not hold: Illuminate\Routing\Matching\UriValidator::
            // matches(), which is what actually matches this request against a route,
            // calls rawurldecode() on the whole path and only then runs preg_match()
            // against the route's compiled regex, so an encoded slash is decoded straight
            // back before that match runs, and a route's default placeholder pattern
            // accepts whatever it decodes into as an ordinary extra path segment. A
            // serverId of "abc/websocket" on the row that reads a server would decode
            // into a path matching the sibling ".../servers/{server}/websocket" route
            // instead of the one this tool was registered for; the literal segments of
            // this row's own path template only keep that from crossing into a different
            // API entirely, such as the application API, not from landing on a sibling
            // route under the same one. rawurlencode() below still runs for whatever this
            // check does not catch, such as a literal "%" a caller's id happens to hold.
            if (str_contains($value, '/')) {
                throw new \InvalidArgumentException(sprintf('The "%s" argument may not contain "/". It selects a single resource by id, and a slash would let the value decide which route this call reaches instead of the tool that was called.', $key));
            }

            $path = str_replace('{' . $key . '}', rawurlencode($value), $path);
        }

        return $path;
    }

    /**
     * The body of the internal request and the content type it is sent with.
     *
     * Throws when the value for a text body is not a scalar.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string|null, 1: string|null}
     *
     * @throws \InvalidArgumentException
     */
    protected function body(array $row, array $arguments): array
    {
        if (($row['body_type'] ?? null) === 'text') {
            $key = $row['text_field'] ?? 'content';

            // An absent argument and an explicit null are both treated the same here, as
            // an empty string: not because they cannot be told apart (array_key_exists()
            // would do exactly that), but because neither carries a different intent worth
            // refusing over, and writing an empty file is a legitimate thing to ask for on
            // purpose. An array or an object is different. It is not a value a text body
            // can represent at all, and is_scalar(null) being false is exactly what lets
            // the check below tell that case apart from these two.
            $value = $arguments[$key] ?? '';

            if (!is_scalar($value)) {
                // Thrown rather than coerced to ''. Coercing here would still let build()
                // hand dispatch() a request with an empty body, which for the file write
                // endpoint truncates whatever file the caller asked to update and reports
                // success regardless, the exact silent data loss this guards against.
                // Refusing before a request exists at all is the only way to guarantee the
                // Panel never sees the call.
                throw new \InvalidArgumentException(sprintf('The "%s" argument must be a string, %s given. Send the file contents as a plain string, or an explicit empty string to write an empty file.', $key, get_debug_type($value)));
            }

            // This has to be the raw body of the request rather than an input field: the
            // file write endpoint reads it back with getContent(), so routing it through
            // the parameter bag instead would write an empty file over the one the caller
            // asked to update and still report success.
            return [(string) $value, 'text/plain'];
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
     * Only the status and the response body of the Panel are ever read here. No header
     * and nothing else off the request object may reach a caller: the request carries the
     * bearer token of the caller, and a tool result is the one thing on this path that is
     * handed back verbatim. The body itself is not forwarded whole either; errors() below
     * reduces an error entry to a fixed set of fields, so what can reach a caller from it
     * is a status, a "detail" message describing what was refused, and, for a validation
     * failure, which argument and which rule, but never a file path, a line number or a
     * stack trace. "detail" can still hold a raw exception message when APP_DEBUG is on:
     * that is the one channel by which a caller learns why a call was refused, and keeping
     * it is deliberate, not an oversight.
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

        $errors = is_array($decoded) && array_key_exists('errors', $decoded) ? $decoded['errors'] : $decoded;

        if (!is_array($errors)) {
            return $errors;
        }

        // Exceptions\Handler::convertExceptionToArray() only ever puts "code", "status"
        // and "detail" on an error entry when APP_DEBUG is off. Handler::invalidJson()
        // additionally, and unconditionally in production as well as debug, folds a "meta"
        // holding "source_field" and "rule" onto a validation failure. That pair is not a
        // leak: it names which argument was rejected and which rule it broke, which is
        // exactly what a model needs to correct a call a validator refused, plausibly the
        // most common refusal this dispatcher ever produces. With APP_DEBUG on,
        // convertExceptionToArray() also merges in "source" (the file and line the
        // exception was thrown from) and folds a stack trace, and for a validation failure
        // the chain of previous exceptions too, into that same "meta". Both of those are
        // exactly what the docblock on result() above promises never reaches a caller.
        // Allowlisting what is safe, at the entry level and again inside "meta", rather
        // than denying what is known to be unsafe today, means a field added to the
        // handler later has to be let in on purpose instead of leaking by default.
        return array_map(function ($error) {
            if (!is_array($error)) {
                return $error;
            }

            $error = array_intersect_key($error, array_flip(['code', 'status', 'detail', 'meta']));

            if (!isset($error['meta']) || !is_array($error['meta'])) {
                unset($error['meta']);

                return $error;
            }

            $meta = array_intersect_key($error['meta'], array_flip(['source_field', 'rule']));

            if ($meta === []) {
                unset($error['meta']);
            } else {
                $error['meta'] = $meta;
            }

            return $error;
        }, $errors);
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
