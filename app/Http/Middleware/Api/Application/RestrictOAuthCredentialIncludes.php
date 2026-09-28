<?php

namespace Pterodactyl\Http\Middleware\Api\Application;

use Illuminate\Http\Request;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Requires the administrative write scope for an application API request that asks for
 * an include whose payload is a stored credential rather than a description of state.
 *
 * The application API picks the permission a request needs from the request class, and
 * the two routes that read a server's databases declare a read. Asking either of them
 * for the "password" include returns the decrypted password of that database user,
 * which is a credential for a different system: it works directly against the database
 * host, and revoking the token that read it does not take it back. A read scope must not
 * hand that out. This is the same rule AuthenticateOAuthScopes::WRITE_GET_ROUTES applies
 * to the client API, a read that grants capability is treated as a write.
 *
 * The include is gated on the write scope rather than refused outright. Creating a
 * database returns its password through this same include, and that is the only place
 * the password of a new database is returned at all, so refusing the include would leave
 * a token that may create databases unable to use one. The write scope is already the
 * one that governs database credentials, since creating a database and resetting its
 * password both require it.
 *
 * Only OAuth requests are affected. An API key keeps being authorized entirely by its
 * own resource permissions, exactly as upstream authorizes it.
 */
class RestrictOAuthCredentialIncludes
{
    /**
     * Includes on the application API whose payload is a credential. ServerDatabaseTransformer
     * is the only application transformer that offers one.
     */
    public const CREDENTIAL_INCLUDES = ['password'];

    public function handle(Request $request, \Closure $next): mixed
    {
        if (!OAuthScopeAcl::isOAuthRequest() || !self::requestsACredential($request)) {
            return $next($request);
        }

        if (!OAuthScopeAcl::tokenCan($request->user()->currentAccessToken(), OAuthScopeAcl::ADMIN_WRITE)) {
            throw new AccessDeniedHttpException(sprintf('Including a stored credential requires the "%s" scope. Repeat the request without the password include to read the rest of the resource.', OAuthScopeAcl::ADMIN_WRITE));
        }

        return $next($request);
    }

    /**
     * Whether the request asks for a credential include anywhere in its include list.
     * The list is read the way ApplicationApiController reads it, from the input rather
     * than only the query string, as either an array or a comma separated string.
     *
     * Fractal treats anything after a colon as modifiers and a dot as nesting, so every
     * segment of every entry is checked rather than only the top level. The nested path
     * through "servers?include=databases.password" is closed for OAuth today, because
     * ServerTransformer::includeDatabases() calls authorize(), which refuses every token
     * that is not an API key. Checking every segment keeps this correct if that changes.
     * The match is on what was asked for rather than on what Fractal would end up running
     * after its recursion limit, so a request nested too deep to ever disclose anything is
     * still refused; the recursion limit is not a security boundary.
     */
    public static function requestsACredential(Request $request): bool
    {
        $input = $request->input('include', []);
        $input = is_array($input) ? $input : explode(',', (string) $input);

        foreach ($input as $include) {
            if (!is_string($include)) {
                continue;
            }

            foreach (explode('.', explode(':', $include, 2)[0]) as $segment) {
                if (in_array(trim($segment), self::CREDENTIAL_INCLUDES, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
