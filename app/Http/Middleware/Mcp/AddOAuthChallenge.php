<?php

namespace Pterodactyl\Http\Middleware\Mcp;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AddOAuthChallenge
{
    /**
     * Attaches the OAuth discovery challenge of RFC 9728 to an unauthenticated response
     * from the MCP endpoint. A client that has never been issued a token makes the request
     * anyway, and the "resource_metadata" parameter on the 401 it gets back is the only
     * thing that tells it where the authorization server for this resource lives.
     *
     * This is a middleware rather than a change to the exception handler because the
     * routing pipeline renders an AuthenticationException into a response at the stage of
     * the middleware that threw it. By the time it has been passed back out this far it is
     * an ordinary response that can still have a header added to it.
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        // Every answer this endpoint gives is JSON, errors included. Without this a client
        // that does not ask for JSON is handed a redirect to the login page when its token
        // is missing or expired, and the 401 that the whole discovery flow hangs off never
        // reaches it.
        $request->headers->set('Accept', 'application/json');

        $response = $request->bearerToken() ? $next($request) : $this->unauthenticated();

        if ($response instanceof Response && $response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            $response->headers->set(
                'WWW-Authenticate',
                sprintf('Bearer resource_metadata="%s"', route('oauth.protected-resource'))
            );
        }

        return $response;
    }

    /**
     * A session cookie authenticates the rest of the Panel, but there is nothing in one to
     * forward to the API on behalf of the caller, so a tool call made with a session would
     * fail on every endpoint it tried. Refusing it here answers with the challenge instead,
     * which is what a client needs to go and get a token it can actually use.
     */
    protected function unauthenticated(): JsonResponse
    {
        return new JsonResponse([
            'errors' => [
                [
                    'code' => 'AuthenticationException',
                    'status' => '401',
                    'detail' => 'This endpoint requires an access token presented as a bearer token.',
                ],
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
