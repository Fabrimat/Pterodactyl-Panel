<?php

namespace Pterodactyl\Http\Middleware;

use Illuminate\Http\Request;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RestrictAdminOAuthScopes
{
    /**
     * Blocks an authorization request that asks for administrative scopes when the
     * account completing the consent screen is not an administrator. This is enforced
     * when the grant is issued rather than when it is used so that a token carrying
     * scopes the account was never entitled to cannot be created in the first place.
     *
     * Only the authorization endpoint needs to be covered: it is the only place a set
     * of scopes enters the system. The refresh token grant is limited by the server to
     * a subset of the scopes that were originally approved.
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        /** @var \Pterodactyl\Models\User|null $user */
        $user = $request->user();
        $scopes = preg_split('/\s+/', (string) $request->input('scope'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($user && !$user->root_admin && OAuthScopeAcl::requiresAdministrator($scopes)) {
            throw new AccessDeniedHttpException('This account does not have permission to grant administrative scopes.');
        }

        return $next($request);
    }
}
