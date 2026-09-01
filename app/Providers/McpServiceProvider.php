<?php

namespace Pterodactyl\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Services\Mcp\EndpointRegistry;
use Pterodactyl\Http\Middleware\Api\IsValidJson;
use Pterodactyl\Http\Controllers\Mcp\McpController;
use Pterodactyl\Http\Middleware\Mcp\AddOAuthChallenge;
use Pterodactyl\Http\Controllers\OAuth\ProtectedResourceController;

class McpServiceProvider extends ServiceProvider
{
    /**
     * Register the services used by the MCP endpoint.
     */
    public function register(): void
    {
        // The endpoint table is a few hundred rows of static data. There is no reason to
        // read it back off disk and re-index it for every tool call in a request.
        $this->app->singleton(EndpointRegistry::class);
    }

    /**
     * Register the MCP endpoint and the discovery document that points a client at the
     * authorization server it needs a token from.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        Route::post('/mcp', [McpController::class, 'handle'])
            // The same "api" group the REST APIs use, which is what brings the multiple
            // guard authentication, the 2FA enforcement and the API key tracking with it,
            // so that reaching the Panel through MCP is not a second way in with its own
            // rules. The throttle is separate: a tool call spends a hit here and another on
            // the REST route it dispatches to, and draining the REST limit from the outside
            // would halve what the same token could do against the API directly.
            ->middleware([AddOAuthChallenge::class, 'api', 'throttle:mcp'])
            // A body that is not valid JSON is a JSON-RPC parse error, which the controller
            // answers with, rather than the generic 400 that middleware would raise first.
            ->withoutMiddleware(IsValidJson::class)
            ->name('mcp.rpc');

        Route::match(['GET', 'DELETE'], '/mcp', [McpController::class, 'unsupportedMethod'])
            ->name('mcp.unsupported');

        Route::get('/.well-known/oauth-protected-resource', ProtectedResourceController::class)
            ->name('oauth.protected-resource');

        // RFC 9728 places the metadata for a resource that is served from a path at that
        // path underneath the well known prefix. A client that was not handed the URL in a
        // WWW-Authenticate challenge looks there first, so the same document answers both.
        Route::get('/.well-known/oauth-protected-resource/mcp', ProtectedResourceController::class)
            ->name('oauth.protected-resource.mcp');
    }

    /**
     * The MCP endpoint gets its own bucket, shaped like the client API one: tied to the
     * account making the request so that switching IP address does not get around it, and
     * falling back to the address only for a request that never authenticated.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('mcp', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinutes(
                config('http.rate_limit.client_period'),
                config('http.rate_limit.client')
            )->by($key);
        });
    }
}
