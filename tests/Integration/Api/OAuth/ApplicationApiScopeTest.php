<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

class ApplicationApiScopeTest extends OAuthIntegrationTestCase
{
    /**
     * An administrator holding the read scope can read from the application API.
     */
    public function testAdminReadScopeCanReadResources(): void
    {
        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_READ]);

        $this->getJson('/api/application/users')
            ->assertOk()
            ->assertJsonPath('object', 'list');
    }

    /**
     * The read scope does not carry the write scope with it.
     */
    public function testAdminReadScopeCannotWriteResources(): void
    {
        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_READ]);

        $this->assertAccessDeniedJson($this->postJson('/api/application/users', $this->userAttributes()));

        $this->assertDatabaseMissing('users', ['username' => 'oauthtestuser']);
    }

    /**
     * An administrator holding the write scope can create resources.
     */
    public function testAdminWriteScopeCanWriteResources(): void
    {
        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_WRITE]);

        $this->postJson('/api/application/users', $this->userAttributes())
            ->assertStatus(Response::HTTP_CREATED);

        $this->assertDatabaseHas('users', ['username' => 'oauthtestuser']);
    }

    /**
     * A token carrying neither administrative scope is refused the same way an API key
     * without the matching resource permission is. Before the OAuth ACL existed this
     * path handed a token that is not an ApiKey to AdminAcl::check(), which declares a
     * typed ApiKey parameter, and the request died with a 500 instead of a 403.
     */
    public function testTokenWithoutAdminScopesIsRefusedWithAccessDenied(): void
    {
        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::CLIENT_READ]);

        $this->assertAccessDeniedJson($this->getJson('/api/application/users'));
    }

    /**
     * Administrative status is checked live on every request, so a token that somehow
     * carries an administrative scope is useless to an account that is not, or is no
     * longer, an administrator.
     */
    public function testAdminScopesAreUselessToANonAdministrator(): void
    {
        /** @var User $user */
        $user = User::factory()->create(['root_admin' => false]);

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::ADMIN_READ, OAuthScopeAcl::ADMIN_WRITE]);

        $this->getJson('/api/application/users')->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * An application API key keeps being authorized entirely by its own resource ACL.
     */
    public function testApplicationApiKeyIsUnaffectedByScopeEnforcement(): void
    {
        /** @var User $user */
        $user = User::factory()->create(['root_admin' => true]);

        /** @var ApiKey $key */
        $key = ApiKey::factory()->for($user)->create([
            'key_type' => ApiKey::TYPE_APPLICATION,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_APPLICATION),
            'r_users' => AdminAcl::READ,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $key->identifier . decrypt($key->token));

        $this->getJson('/api/application/users')->assertOk();
        $this->assertAccessDeniedJson($this->postJson('/api/application/users', $this->userAttributes()));
    }

    /**
     * Creates an administrator to authenticate the application API requests with.
     */
    private function createAdministrator(): User
    {
        /** @var User $user */
        $user = User::factory()->create(['root_admin' => true]);

        return $user;
    }

    /**
     * Returns a valid payload for creating a user through the application API.
     */
    private function userAttributes(): array
    {
        return [
            'username' => 'oauthtestuser',
            'email' => 'oauth-test@example.com',
            'first_name' => 'OAuth',
            'last_name' => 'Test',
        ];
    }
}
