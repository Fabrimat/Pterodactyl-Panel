<?php

namespace Pterodactyl\Http\Middleware\Api;

use Illuminate\Http\Request;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Requires a write scope for a request that asks for an include whose payload is a
 * stored credential rather than a description of state. It is registered on both APIs,
 * with the write scope of that API as its parameter.
 *
 * Reading a server's databases is a read on both the client and the application API,
 * but asking either for the "password" include returns the decrypted password of the
 * database user. That is a credential for a different system: it works directly against
 * the database host, and revoking the token that read it does not take it back. A read
 * scope must not hand that out, for the same reason files/upload and websocket are
 * treated as writes on the client API.
 *
 * The include is gated on the write scope rather than refused outright. On both APIs,
 * creating a database returns its password and already requires the write scope, and
 * the client API also returns it when rotating a password, a write as well. A token
 * holding the write scope is therefore already trusted with these credentials, and
 * refusing the include outright would only stop it reading one back later.
 *
 * Only OAuth requests are affected. An API key, a session, or anything else that was
 * not authenticated by the OAuth guard passes through exactly as upstream handles it.
 */
class RestrictOAuthCredentialIncludes
{
    /**
     * Includes whose payload is a credential. The database transformers of the two APIs
     * are the only ones that offer one.
     */
    public const CREDENTIAL_INCLUDES = ['password'];

    public function handle(Request $request, \Closure $next, string $scope): mixed
    {
        if (!OAuthScopeAcl::isOAuthRequest() || !self::requestsACredential($request)) {
            return $next($request);
        }

        if (!OAuthScopeAcl::tokenCan($request->user()->currentAccessToken(), $scope)) {
            throw new AccessDeniedHttpException(sprintf('Including a stored credential requires the "%s" scope. Repeat the request without the password include to read the rest of the resource.', $scope));
        }

        return $next($request);
    }

    /**
     * Whether the request asks for a credential include anywhere in its include list.
     * The list is read from the input, as either an array or a comma separated string,
     * which covers both the application API (it reads the input) and the client API (it
     * reads only the query string, a subset of the input).
     *
     * Each entry is reduced the way Fractal resolves it. A colon starts a modifier that
     * runs up to the next dot, and whatever follows that dot is still a nested include,
     * so "databases:limit(1).password" asks for "databases.password". Modifiers are
     * stripped and every remaining segment is checked, not only the top level. Nested
     * paths are closed for OAuth today, because every include that leads to a database
     * calls authorize(), which refuses any token that is not an API key, but checking
     * every segment keeps this correct if that changes. The match is on what was asked
     * for rather than on what Fractal would run after its recursion limit, so a request
     * nested too deep to disclose anything is still refused; the recursion limit is not
     * a security boundary.
     */
    public static function requestsACredential(Request $request): bool
    {
        $input = $request->input('include', []);
        $input = is_array($input) ? $input : explode(',', (string) $input);

        foreach ($input as $include) {
            if (!is_string($include)) {
                continue;
            }

            foreach (explode('.', preg_replace('/:[^.]*/', '', $include)) as $segment) {
                if (in_array(trim($segment), self::CREDENTIAL_INCLUDES, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
