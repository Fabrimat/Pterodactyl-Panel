<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

class AccountCredentialRouteTest extends OAuthIntegrationTestCase
{
    /**
     * An OAuth access token expires and only carries the scopes a user consented to.
     * The routes covered here either hand out a permanent API key or replace one of
     * the credentials protecting the account, so allowing a scoped token to reach them
     * would let it trade itself in for unlimited permanent access.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('credentialRouteProvider')]
    public function testCredentialRouteIsRefusedForOAuthTokens(string $method, string $url): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ, OAuthScopeAcl::CLIENT_WRITE]);

        $this->json($method, $url)->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * Nothing was created as a side effect of the refused request.
     */
    public function testOAuthTokenCannotMintAnApiKey(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_WRITE]);

        $this->postJson('/api/client/account/api-keys', [
            'description' => 'Escalation',
            'allowed_ips' => [],
        ])->assertStatus(Response::HTTP_FORBIDDEN);

        $this->assertDatabaseMissing('api_keys', ['user_id' => $user->id]);
    }

    /**
     * The same routes must keep working for a client API key, they are only closed off
     * to OAuth access tokens.
     */
    public function testCredentialRoutesStillWorkForApiKeys(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAsApiKeyUser($user);

        $this->getJson('/api/client/account/api-keys')->assertOk();
        $this->getJson('/api/client/account/ssh-keys')->assertOk();

        $this->postJson('/api/client/account/api-keys', [
            'description' => 'Test Key',
            'allowed_ips' => [],
        ])->assertOk();

        $this->assertDatabaseHas('api_keys', ['user_id' => $user->id, 'memo' => 'Test Key']);
    }

    /**
     * An account can still be read through an OAuth token, only the credential routes
     * are closed off.
     */
    public function testAccountDetailsAreStillReadable(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $this->getJson('/api/client/account')
            ->assertOk()
            ->assertJsonPath('attributes.email', $user->email);
    }

    /**
     * Every route that must refuse an OAuth access token no matter which scopes it
     * was granted.
     */
    public static function credentialRouteProvider(): array
    {
        return [
            'list api keys' => ['GET', '/api/client/account/api-keys'],
            'create api key' => ['POST', '/api/client/account/api-keys'],
            'delete api key' => ['DELETE', '/api/client/account/api-keys/ptlc_abcdefghijk'],
            'list ssh keys' => ['GET', '/api/client/account/ssh-keys'],
            'create ssh key' => ['POST', '/api/client/account/ssh-keys'],
            'delete ssh key' => ['POST', '/api/client/account/ssh-keys/remove'],
            'change email' => ['PUT', '/api/client/account/email'],
            'change password' => ['PUT', '/api/client/account/password'],
        ];
    }
}
