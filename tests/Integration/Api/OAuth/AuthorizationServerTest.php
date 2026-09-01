<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Pterodactyl\Http\Middleware\RestrictAdminOAuthScopes;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;

class AuthorizationServerTest extends OAuthIntegrationTestCase
{
    /**
     * A client discovers the authorization server through this document before it has
     * any credentials at all, so it has to be readable without authenticating.
     */
    public function testMetadataDocumentIsServedWithoutAuthentication(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $response->assertJsonPath('issuer', rtrim(config('app.url'), '/'));
        $response->assertJsonPath('response_types_supported', ['code']);
        $response->assertJsonPath('code_challenge_methods_supported', ['S256']);

        $this->assertSame(route('passport.authorizations.authorize'), $response->json('authorization_endpoint'));
        $this->assertSame(route('passport.token'), $response->json('token_endpoint'));
        $this->assertSame(array_keys(OAuthScopeAcl::scopes()), $response->json('scopes_supported'));
        $this->assertContains('authorization_code', $response->json('grant_types_supported'));
        $this->assertContains('refresh_token', $response->json('grant_types_supported'));
    }

    /**
     * Exactly four scopes are offered, no more.
     */
    public function testOnlyTheFourPanelScopesAreRegistered(): void
    {
        $this->assertCount(4, Passport::scopes());

        foreach (array_keys(OAuthScopeAcl::scopes()) as $scope) {
            $this->assertTrue(Passport::hasScope($scope), "The $scope scope was not registered with Passport.");
        }
    }

    /**
     * Passport registers its own routes wrapped in nothing but the "web" group, which
     * would let an account that is locked out of the Panel pending 2FA enrollment walk
     * through a consent screen and collect an API capable token. It would also let a
     * regular account approve a grant asking for administrative scopes.
     */
    public function testEveryAuthorizationRouteIsGuarded(): void
    {
        $routes = collect(Route::getRoutes())->filter(function ($route) {
            return str_starts_with((string) $route->getName(), 'passport.authorizations.');
        });

        $this->assertNotEmpty($routes, 'Passport did not register any authorization routes.');
        $this->assertNotNull(Route::getRoutes()->getByName('passport.authorizations.authorize'));

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $name = $route->getName();

            $this->assertContains('auth', $middleware, "The $name route is not authenticated.");
            $this->assertContains(RequireTwoFactorAuthentication::class, $middleware, "The $name route does not honor the two-factor requirement.");
            $this->assertContains(RestrictAdminOAuthScopes::class, $middleware, "The $name route does not restrict administrative scopes.");
        }
    }
}
