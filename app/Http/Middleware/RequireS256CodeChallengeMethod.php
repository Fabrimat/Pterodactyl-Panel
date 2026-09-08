<?php

namespace Pterodactyl\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequireS256CodeChallengeMethod
{
    /**
     * Refuses an authorization request that carries a PKCE code challenge whose method
     * is anything but "S256". The discovery document and OAUTH.md both advertise S256
     * as the only supported method, but the authorization code grant this Panel runs
     * on top of registers a "plain" verifier unconditionally and there is no way for a
     * fork to remove it: the property holding the list of verifiers is private, so a
     * subclass cannot drop PlainVerifier from it. A "plain" challenge is just the
     * verifier compared against itself, which is not meaningfully different from
     * sending no PKCE at all, so letting it through the front door silently defeats
     * the protection PKCE exists to provide.
     *
     * Omitting "code_challenge_method" while "code_challenge" is present is the
     * dangerous case, not the safe one: the library defaults the method to "plain"
     * rather than rejecting the request, so a client that never asked for the weak
     * method by name still gets it, and so does an attacker who strips the parameter
     * off an intercepted authorization URL. That combination is refused here exactly
     * the same as an explicit "code_challenge_method=plain".
     *
     * No "code_challenge" at all is let through on purpose. A confidential client is
     * permitted, by the specification and by the library itself, to omit PKCE
     * entirely, and a public client that omits it is already refused by the grant
     * before this middleware would ever get a say, since Passport leaves
     * "requireCodeChallengeForPublicClients" at its default of true. Refusing here
     * too would duplicate that check for one client type and wrongly break the other.
     *
     * Read from the query string with query(), not input(). The grant this middleware
     * guards, AuthCodeGrant::validateAuthorizationRequest(), reads both parameters with
     * getQueryStringParameter(), which only ever looks at the query string. input()
     * layers a JSON body over the query string on a GET request, so it could be
     * satisfied by a value the grant never sees while the query string it actually acts
     * on says something else. Guarding a different source than the one being guarded
     * is not a guard.
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        $codeChallenge = $request->query('code_challenge');

        if ($codeChallenge !== null && $request->query('code_challenge_method', 'plain') !== 'S256') {
            throw new AccessDeniedHttpException('This authorization server only accepts a PKCE code challenge method of S256.');
        }

        return $next($request);
    }
}
