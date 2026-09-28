<?php

namespace Pterodactyl\Http\Middleware\Api\Client;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthenticateOAuthScopes
{
    /**
     * Routes that hand out or replace the credentials protecting an account. An OAuth
     * access token is short lived and limited to the scopes a user consented to, so it
     * must never be usable to mint a permanent API key, register an SSH key, or take
     * the account over by changing the email address or password.
     *
     * The two factor routes are included because requesting the setup data rewrites the
     * TOTP secret on the account and hands back the plain text of the new one, which is
     * a credential change hiding behind a GET request.
     */
    public const PROTECTED_ROUTES = [
        'api/client/account/api-keys',
        'api/client/account/ssh-keys',
        'api/client/account/two-factor',
        'api/client/account/email',
        'api/client/account/password',
    ];

    /**
     * Routes that answer a GET request with something a write scope should gate, because
     * the response itself grants capability rather than merely describing state. Uploading
     * a file returns a signed Wings URL that accepts arbitrary file writes, and connecting
     * to the websocket returns a node JWT carrying the user's full permission set for that
     * server, which Wings honours for console commands and power actions. Deriving the
     * scope from the HTTP verb alone would let a client:read token reach both.
     */
    public const WRITE_GET_ROUTES = [
        'api/client/servers/{server}/files/upload',
        'api/client/servers/{server}/websocket',
    ];

    /**
     * Whether the given route URI is one of the GET routes that must be treated as a
     * write for scope purposes. Both sides are normalised before comparing: the router
     * gives back the placeholder names it was registered with (e.g. "{server}"), while
     * the MCP endpoint table uses its own names for the same segment (e.g. "{serverId}"),
     * so a placeholder is folded down to a bare "{}" before the comparison is made. The
     * match is exact rather than a prefix match on purpose, so that a future route nested
     * under one of these (e.g. "files/upload/something") does not silently inherit a
     * classification nobody reviewed.
     */
    public static function isWriteRoute(string $uri): bool
    {
        $normalised = trim(preg_replace('/\{[^}]+\}/', '{}', $uri), '/');

        $routes = array_map(
            fn (string $route) => trim(preg_replace('/\{[^}]+\}/', '{}', $route), '/'),
            self::WRITE_GET_ROUTES
        );

        return in_array($normalised, $routes, true);
    }

    /**
     * Enforces the scopes that were granted to an OAuth access token against the client
     * API. Requests that the OAuth guard did not authenticate were authenticated earlier
     * in the chain by an API key or a session, neither of which is scoped, so they are
     * passed straight through with their behaviour unchanged.
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!OAuthScopeAcl::isOAuthRequest()) {
            return $next($request);
        }

        if (Str::startsWith($request->route()->uri(), self::PROTECTED_ROUTES)) {
            throw new AccessDeniedHttpException('This endpoint cannot be accessed using an OAuth access token.');
        }

        // Checked route-first rather than verb-first: Laravel registers HEAD alongside
        // every GET route, and a verb-first branch would leave HEAD requests against the
        // two write routes above unclassified.
        $write = !in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || self::isWriteRoute($request->route()->uri());

        $scope = $write ? OAuthScopeAcl::CLIENT_WRITE : OAuthScopeAcl::CLIENT_READ;

        if (!OAuthScopeAcl::tokenCan($request->user()->currentAccessToken(), $scope)) {
            throw new AccessDeniedHttpException(sprintf('This OAuth access token was not granted the "%s" scope required to make this request.', $scope));
        }

        return $next($request);
    }
}
