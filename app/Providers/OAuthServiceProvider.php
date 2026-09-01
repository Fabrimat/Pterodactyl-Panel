<?php

namespace Pterodactyl\Providers;

use Laravel\Passport\Passport;
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

        Route::get('/.well-known/oauth-authorization-server', AuthorizationServerController::class)
            ->name('oauth.metadata');

        // Passport registers its own routes, so the only opportunity to add middleware to
        // them is once every route in the application has been loaded.
        $this->app->booted(function () {
            foreach (Route::getRoutes() as $route) {
                if (str_starts_with((string) $route->getName(), 'passport.authorizations.')) {
                    $route->middleware($this->authorizationMiddleware);
                }
            }
        });
    }
}
