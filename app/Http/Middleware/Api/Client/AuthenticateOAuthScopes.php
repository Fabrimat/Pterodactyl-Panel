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
     */
    public const PROTECTED_ROUTES = [
        'api/client/account/api-keys',
        'api/client/account/ssh-keys',
        'api/client/account/email',
        'api/client/account/password',
    ];

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

        $scope = in_array($request->getMethod(), ['GET', 'HEAD'], true)
            ? OAuthScopeAcl::CLIENT_READ
            : OAuthScopeAcl::CLIENT_WRITE;

        if (!OAuthScopeAcl::tokenCan($request->user()->currentAccessToken(), $scope)) {
            throw new AccessDeniedHttpException(sprintf('This OAuth access token was not granted the "%s" scope required to make this request.', $scope));
        }

        return $next($request);
    }
}
