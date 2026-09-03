<?php

namespace Pterodactyl\Tests\Integration\Api\Mcp;

use Illuminate\Http\Request;
use Pterodactyl\Services\Mcp\EndpointRegistry;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RouteResolutionTest extends McpIntegrationTestCase
{
    /**
     * The table is plain data that nothing else checks against the routes of the
     * Panel: a typo in a row's path or method produces a tool that always 404s, and
     * neither the table itself nor ToolCatalogTest (which derives its expectations
     * from the same table) would ever notice. This resolves every row's basePath +
     * path, with {placeholders} substituted, against the real route collection so a
     * mismatch is caught here instead of by whoever calls the tool first.
     */
    public function testEveryRowResolvesToARegisteredRoute(): void
    {
        $registry = $this->app->make(EndpointRegistry::class);
        $rows = $registry->all();
        $this->assertNotEmpty($rows);

        foreach ($rows as $name => $row) {
            $method = strtoupper((string) ($row['method'] ?? 'GET'));
            $path = EndpointRegistry::basePath($row) . preg_replace('/\{[^}]+\}/', '1', (string) $row['path']);

            $route = null;

            try {
                $route = $this->app['router']->getRoutes()->match(Request::create($path, $method));
            } catch (HttpExceptionInterface $e) {
                $this->fail(sprintf(
                    '%s (%s %s) does not resolve to a registered route: %s',
                    $name,
                    $method,
                    $path,
                    $e->getMessage() ?: get_class($e)
                ));
            }

            $this->assertNotNull($route, $name . ' (' . $method . ' ' . $path . ') did not match any route.');
        }
    }
}
