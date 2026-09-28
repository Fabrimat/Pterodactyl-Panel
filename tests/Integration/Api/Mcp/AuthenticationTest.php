<?php

namespace Pterodactyl\Tests\Integration\Api\Mcp;

use Illuminate\Http\Response;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

class AuthenticationTest extends McpIntegrationTestCase
{
    /**
     * A client that has never been issued a token still needs to be told where to get
     * one. The "resource_metadata" parameter on the 401 is the only thing that points
     * it at the discovery document.
     */
    public function testUnauthenticatedRequestCarriesTheOAuthChallenge(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
        $response->assertHeader(
            'WWW-Authenticate',
            sprintf('Bearer resource_metadata="%s"', rtrim(config('app.url'), '/') . route('oauth.protected-resource', [], false))
        );
    }

    /**
     * TRUSTED_PROXIES is set to "*" in production, which makes an X-Forwarded-Host
     * header authoritative for what the request appears to have arrived on, and a bare
     * Host header is trusted by Symfony regardless of that setting. Posting to an
     * absolute URL naming a different host reproduces the effect of either directly:
     * Symfony's Request::create() takes the host straight from the URL it is given,
     * and Laravel's test client passes an already-absolute URL straight through
     * instead of prefixing it, so this does not depend on how trusted proxies happen
     * to be configured under test. The header must still point at the configured
     * application URL, never at the host the request arrived on.
     */
    public function testChallengeIgnoresTheRequestHost(): void
    {
        $response = $this->postJson('http://evil.example/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
        $response->assertHeader(
            'WWW-Authenticate',
            sprintf('Bearer resource_metadata="%s"', rtrim(config('app.url'), '/') . route('oauth.protected-resource', [], false))
        );
    }

    public function testProtectedResourceMetadataIsPubliclyReadable(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource')->assertOk();

        $url = rtrim(config('app.url'), '/');
        $response->assertJsonPath('resource', $url . '/mcp');
        $response->assertJsonPath('authorization_servers', [$url]);
        $response->assertJsonPath('bearer_methods_supported', ['header']);
        $this->assertEqualsCanonicalizing(array_keys(OAuthScopeAcl::scopes()), $response->json('scopes_supported'));
    }

    /**
     * RFC 9728 places the metadata for a resource served from a path underneath the
     * well known prefix at that path too, for a client that never saw a challenge.
     */
    public function testProtectedResourceMetadataIsAlsoServedUnderThePathInsertionForm(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();

        $response->assertJsonPath('resource', rtrim(config('app.url'), '/') . '/mcp');
        $this->assertEqualsCanonicalizing(array_keys(OAuthScopeAcl::scopes()), $response->json('scopes_supported'));
    }
}
