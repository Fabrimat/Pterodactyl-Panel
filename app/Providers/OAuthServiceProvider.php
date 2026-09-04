<?php

namespace Pterodactyl\Providers;

use Laravel\Passport\Passport;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Pterodactyl\Http\Middleware\RestrictAdminOAuthScopes;
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
    ];

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
