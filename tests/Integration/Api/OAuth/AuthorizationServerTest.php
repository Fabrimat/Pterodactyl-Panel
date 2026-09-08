<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Pterodactyl\Http\Middleware\RestrictAdminOAuthScopes;
use Pterodactyl\Http\Middleware\RequireS256CodeChallengeMethod;
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

        $appUrl = rtrim(config('app.url'), '/');
        $this->assertSame($appUrl . route('passport.authorizations.authorize', [], false), $response->json('authorization_endpoint'));
        $this->assertSame($appUrl . route('passport.token', [], false), $response->json('token_endpoint'));
        $this->assertSame(array_keys(OAuthScopeAcl::scopes()), $response->json('scopes_supported'));
        $this->assertContains('authorization_code', $response->json('grant_types_supported'));
        $this->assertContains('refresh_token', $response->json('grant_types_supported'));
    }

    /**
     * TRUSTED_PROXIES is set to "*" in production, which makes an X-Forwarded-Host
     * header authoritative for what the request appears to have arrived on, and a bare
     * Host header is trusted by Symfony regardless of that setting. Requesting the
     * document through an absolute URL naming a different host reproduces the effect of
     * either directly: Symfony's Request::create() takes the host straight from the URL
     * it is given, and Laravel's test client passes an already-absolute URL straight
     * through instead of prefixing it, so this does not depend on how trusted proxies
     * happen to be configured under test. The endpoints must still point at the
     * configured application URL, never at the host the request arrived on.
     */
    public function testMetadataDocumentIgnoresTheRequestHost(): void
    {
        $response = $this->getJson('http://evil.example/.well-known/oauth-authorization-server');

        $response->assertOk();

        $appUrl = rtrim(config('app.url'), '/');
        $this->assertSame($appUrl, $response->json('issuer'));
        $this->assertSame($appUrl . route('passport.authorizations.authorize', [], false), $response->json('authorization_endpoint'));
        $this->assertSame($appUrl . route('passport.token', [], false), $response->json('token_endpoint'));
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
            $this->assertContains(RequireS256CodeChallengeMethod::class, $middleware, "The $name route does not restrict the PKCE code challenge method.");
        }
    }

    /**
     * The Panel does not use the device authorization flow, and Passport::$deviceCodeGrantEnabled
     * is turned off in OAuthServiceProvider::register() specifically so these routes never come
     * into existence: RestrictAdminOAuthScopes reads the requested scopes off the request, and a
     * device approval carries none there at all, so attaching that middleware to the device routes
     * would not close the gap a device grant opens onto the admin scope check. The routes not
     * existing is the whole defence, which is exactly what this asserts. It fails if the flag is
     * ever moved back into boot(), where Passport has already read it and registered these routes
     * before this provider gets a turn, or if the flag is dropped entirely.
     */
    public function testDeviceAuthorizationRoutesDoNotExist(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('passport.device'), 'The device user code route exists.');
        $this->assertNull(Route::getRoutes()->getByName('passport.device.code'), 'The device code route exists.');
        $this->assertNull(Route::getRoutes()->getByName('passport.device.authorizations.authorize'), 'The device authorization route exists.');
        $this->assertNull(Route::getRoutes()->getByName('passport.device.authorizations.approve'), 'The device approval route exists.');
        $this->assertNull(Route::getRoutes()->getByName('passport.device.authorizations.deny'), 'The device denial route exists.');
    }
}
