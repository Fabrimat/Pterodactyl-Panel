<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Pterodactyl\Http\Middleware\Api\Client\AuthenticateOAuthScopes;

/**
 * The OAuth scope middleware derives client:read/client:write from the HTTP verb by
 * default, and only the routes named here and in AuthenticateOAuthScopes are treated
 * as an exception to that rule. This test enumerates every GET route the client API
 * actually registers and fails loudly if one appears that this file has no opinion
 * on, so a route added upstream cannot silently inherit a classification nobody
 * reviewed.
 */
class ClientGetRouteClassificationTest extends OAuthIntegrationTestCase
{
    /**
     * Every client GET route that is a genuine read: nothing here returns a
     * credential or a capability that Wings would honour on its own. This is a
     * one-time classification, checked against exact normalised URIs rather than
     * prefixes so that a future route nested under one of these does not silently
     * pass unreviewed.
     *
     * The databases route is a read only without its password include. That include
     * is gated separately, on the include rather than the route, by
     * RestrictOAuthCredentialIncludes.
     */
    private const KNOWN_READ = [
        'api/client',
        'api/client/permissions',
        'api/client/account',
        'api/client/account/activity',
        'api/client/servers/{}',
        'api/client/servers/{}/resources',
        'api/client/servers/{}/activity',
        'api/client/servers/{}/databases',
        'api/client/servers/{}/files/list',
        'api/client/servers/{}/files/contents',
        'api/client/servers/{}/files/download',
        'api/client/servers/{}/schedules',
        'api/client/servers/{}/schedules/{}',
        'api/client/servers/{}/network/allocations',
        'api/client/servers/{}/users',
        'api/client/servers/{}/users/{}',
        'api/client/servers/{}/backups',
        'api/client/servers/{}/backups/{}',
        'api/client/servers/{}/backups/{}/download',
        'api/client/servers/{}/startup',
    ];

    /**
     * Every GET route the client API registers must fall into exactly one of the
     * three known sets: a plain read, a route refused outright for OAuth tokens
     * regardless of scope, or a GET that this file already knows hands out write
     * capability. A route matching none of them is exactly the drift this test
     * exists to catch.
     */
    public function testEveryClientGetRouteIsClassified(): void
    {
        $routes = array_filter(
            $this->app['router']->getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/client') && in_array('GET', $route->methods(), true)
        );

        // A filter that silently stopped matching anything would still let every
        // assertion below pass vacuously, so the count is checked directly first.
        $this->assertGreaterThan(20, count($routes), 'Expected to find more than 20 client API GET routes.');

        $writeRoutes = array_map([self::class, 'normalise'], AuthenticateOAuthScopes::WRITE_GET_ROUTES);
        $protectedRoutes = array_map([self::class, 'normalise'], AuthenticateOAuthScopes::PROTECTED_ROUTES);
        $knownRead = array_map([self::class, 'normalise'], self::KNOWN_READ);

        foreach ($routes as $route) {
            $uri = self::normalise($route->uri());

            $inKnownRead = in_array($uri, $knownRead, true);
            $inWriteRoutes = in_array($uri, $writeRoutes, true);
            $inProtectedRoutes = in_array($uri, $protectedRoutes, true);

            $this->assertSame(
                1,
                (int) $inKnownRead + (int) $inWriteRoutes + (int) $inProtectedRoutes,
                sprintf(
                    '%s must fall into exactly one of KNOWN_READ, WRITE_GET_ROUTES or PROTECTED_ROUTES, matched %d.',
                    $route->uri(),
                    (int) $inKnownRead + (int) $inWriteRoutes + (int) $inProtectedRoutes
                )
            );
        }
    }

    /**
     * Every route named in WRITE_GET_ROUTES must resolve to something actually
     * registered. The classification test above only proves the list is exhaustive
     * against what the router currently has; it would not by itself catch an
     * upstream rename of one of these two routes, since the renamed route would just
     * show up as an unclassified GET and this assertion pins the failure to the
     * exact route that moved.
     */
    public function testEveryWriteGetRouteResolvesToARegisteredRoute(): void
    {
        foreach (AuthenticateOAuthScopes::WRITE_GET_ROUTES as $route) {
            $path = '/' . preg_replace('/\{[^}]+\}/', '1', $route);

            $resolved = null;

            try {
                $resolved = $this->app['router']->getRoutes()->match(Request::create($path, 'GET'));
            } catch (HttpExceptionInterface $e) {
                $this->fail(sprintf(
                    '%s (GET %s) does not resolve to a registered route: %s',
                    $route,
                    $path,
                    $e->getMessage() ?: get_class($e)
                ));
            }

            $this->assertNotNull($resolved, $route . ' (GET ' . $path . ') did not match any route.');
        }
    }

    /**
     * Folds placeholders down to a bare "{}" and trims surrounding slashes, exactly
     * as AuthenticateOAuthScopes::isWriteRoute() does, so a route coming from the
     * router compares equal to the same route written with a different placeholder
     * name in one of the constants under test.
     */
    private static function normalise(string $uri): string
    {
        return trim(preg_replace('/\{[^}]+\}/', '{}', $uri), '/');
    }
}
