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
            // The host comes from the configured application URL rather than from this
            // request, which this Panel's proxy configuration trusts and which an
            // attacker can therefore steer. route()'s third argument returns only the
            // path, so the value still follows the route if it is ever renamed, while
            // nothing about the host can be influenced by the request that triggered
            // this challenge.
            $resourceMetadata = rtrim(config('app.url'), '/') . route('oauth.protected-resource', [], false);

            $response->headers->set(
                'WWW-Authenticate',
                sprintf('Bearer resource_metadata="%s"', $resourceMetadata)
            );
        }

        return $response;
    }

    /**
     * This is not a security boundary: the guard stack behind $next() accepts a session
     * exactly as readily as a bearer token, so a caller who already holds a session cookie
     * for the Panel loses nothing by attaching an arbitrary bearer string and continuing
     * past this check, and gains nothing either, since they could already reach the same
     * API directly from the browser they are signed into.
     *
     * The reason to refuse here rather than let the request continue is what a client
     * following the discovery flow of RFC 9728 is actually looking for: a 401 carrying
     * "resource_metadata" on a request that carries no bearer token at all. Letting such a
     * request fall through to the guard stack risks it being authenticated some other way
     * before it ever gets a chance to fail, which would answer it with a normal response
     * instead of the challenge the client came here to find.
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
