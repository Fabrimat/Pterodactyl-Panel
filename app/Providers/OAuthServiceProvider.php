<?php

namespace Pterodactyl\Providers;

use Laravel\Passport\Passport;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Events\User\Deleting;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Events\User\PasswordChanged;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Pterodactyl\Listeners\OAuthRevocationListener;
use Pterodactyl\Http\Middleware\RestrictAdminOAuthScopes;
use Pterodactyl\Http\Middleware\RequireS256CodeChallengeMethod;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Http\Controllers\OAuth\AuthorizationServerController;

class OAuthServiceProvider extends ServiceProvider
{
    /**
     * Middleware that is pushed onto the authorization endpoints registered by Passport.
     * The package only wraps those routes in the "web" group, which means a user that is
     * locked out of the rest of the Panel pending 2FA enrollment would otherwise still be
     * able to complete a consent screen and walk away with an API capable token.
     */
    protected array $authorizationMiddleware = [
        'auth',
        RequireTwoFactorAuthentication::class,
        RestrictAdminOAuthScopes::class,
        RequireS256CodeChallengeMethod::class,
    ];

    /**
     * Passport reads this flag the moment it registers its own routes, inside its own
     * boot(). Passport's service provider is discovered automatically by composer, and
     * registerConfiguredProviders() always places auto-discovered providers ahead of
     * the ones this application lists explicitly in config/app.php, so Passport is both
     * registered and booted before this provider gets a turn. Setting the flag from
     * this provider's own boot() would therefore be silently too late: the route
     * registration it is meant to prevent would already have happened by the time it
     * ran. register() is the one phase every provider goes through before any
     * provider's boot() runs at all, which is the only place this is guaranteed to
     * land in time.
     *
     * The Panel does not use the device authorization flow, and disabling it here also
     * removes the "Would you like to enable the device authorization flow for this
     * client?" prompt from `passport:client --public`, the exact command OAUTH.md has
     * an administrator run to create a client. Answering that prompt once is enough to
     * register routes that carry neither the 2FA requirement nor the administrative
     * scope restriction above: RestrictAdminOAuthScopes reads the requested scopes off
     * the request, and a device approval carries none there at all, the scopes for a
     * device grant live on the device code entity instead, so attaching the middleware
     * to those routes would not close the gap either without teaching it to read
     * scopes from somewhere else entirely. The routes not existing is the fix, not
     * something layered on top of them.
     */
    public function register(): void
    {
        Passport::$deviceCodeGrantEnabled = false;
    }

    /**
     * Bootstrap the OAuth authorization server.
     */
    public function boot(): void
    {
        Passport::tokensCan(OAuthScopeAcl::scopes());
        Passport::tokensExpireIn(now()->addDays(7));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        if (!$this->hasSigningKeys()) {
            $this->disableOAuthGuard();
        }

        Route::get('/.well-known/oauth-authorization-server', AuthorizationServerController::class)
            ->name('oauth.metadata');

        // Passport registers its own routes, so the only opportunity to add middleware to
        // them is once every route in the application has been loaded.
        $this->app->booted(function () {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (str_starts_with((string) $route->getName(), 'passport.authorizations.')) {
                    $route->middleware($this->authorizationMiddleware);
                }
            }
        });

        // Registered here rather than added to the upstream RevocationListener, or to
        // EventServiceProvider's subscriber list, because both of those are
        // upstream-owned: RevocationListener already answers to these same two events
        // for a different concern (SFTP and websocket sessions on Wings), and editing
        // either would cost this fork its mergeability for no reason. Both events
        // already dispatch on every path that matters: PasswordChanged from the client
        // API, the admin area and the forgotten-password flow, and Deleting on account
        // deletion.
        Event::listen(PasswordChanged::class, OAuthRevocationListener::class);
        Event::listen(Deleting::class, OAuthRevocationListener::class);
    }

    /**
     * Determine if the keys Passport signs and verifies access tokens with are present,
     * either on disk or supplied through the environment.
     */
    protected function hasSigningKeys(): bool
    {
        return !empty(config('passport.public_key')) || file_exists(Passport::keyPath('oauth-public.key'));
    }

    /**
     * Replaces the guard Passport registers with one that never authenticates anybody.
     *
     * That guard is consulted on every API request Sanctum turns down, and building it
     * reads the public key from disk. On an installation where "passport:keys" has not
     * been run that read throws a LogicException, which the exception handler can only
     * render as a 500, turning every unauthenticated request into a server error. No
     * access token can have been issued or verified without the keys, so resolving to
     * nobody is both accurate and lets the request continue to the usual 401.
     */
    protected function disableOAuthGuard(): void
    {
        Auth::resolved(function ($auth) {
            $auth->extend('passport', function ($app, $name, array $config) use ($auth) {
                return new RequestGuard(
                    fn () => null,
                    $app['request'],
                    $auth->createUserProvider($config['provider'] ?? null)
                );
            });
        });
    }
}
