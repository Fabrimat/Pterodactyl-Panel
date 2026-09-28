<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

/**
 * The password include on a server database returns the decrypted password of that
 * database user, on both the application and the client API. Each refusal is asserted in its own test: Handler::render() rolls every
 * open transaction back to level 0, which under DatabaseTransactions discards the
 * fixtures, so a second request in the same test would not find them.
 */
class DatabasePasswordIncludeTest extends OAuthIntegrationTestCase
{
    /**
     * A read scope cannot include the password when listing a server's databases.
     */
    public function testReadScopeCannotIncludeThePasswordWhenListing(): void
    {
        [, $server] = $this->createDatabase();

        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_READ]);

        $this->assertCredentialRefused($this->getJson("/api/application/servers/$server->id/databases?include=password"));
    }

    /**
     * A read scope cannot include the password when viewing a single database.
     */
    public function testReadScopeCannotIncludeThePasswordWhenViewing(): void
    {
        [$database, $server] = $this->createDatabase();

        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_READ]);

        $this->assertCredentialRefused($this->getJson("/api/application/servers/$server->id/databases/$database->id?include=host,password"));
    }

    /**
     * The same include reached through a server's databases is refused as well.
     */
    public function testReadScopeCannotIncludeThePasswordThroughAServer(): void
    {
        [, $server] = $this->createDatabase();

        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_READ]);

        $this->assertCredentialRefused($this->getJson("/api/application/servers/$server->id?include=databases.password"));
    }

    /**
     * Only the include is refused. The rest of the resource stays readable with the
     * read scope, and the password never appears in it.
     */
    public function testReadScopeCanStillReadDatabasesWithoutThePassword(): void
    {
        [$database, $server] = $this->createDatabase();

        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_READ]);

        $this->getJson("/api/application/servers/$server->id/databases/$database->id?include=host")
            ->assertOk()
            ->assertJsonPath('attributes.id', $database->id)
            ->assertJsonMissingPath('attributes.relationships.password');
    }

    /**
     * A token that also holds the write scope can include the password. Creating a
     * database returns its password the same way under that scope.
     */
    public function testWriteScopeCanIncludeThePassword(): void
    {
        [$database, $server] = $this->createDatabase();

        $this->actingAsOAuthUser($this->createAdministrator(), [OAuthScopeAcl::ADMIN_READ, OAuthScopeAcl::ADMIN_WRITE]);

        $this->getJson("/api/application/servers/$server->id/databases/$database->id?include=password")
            ->assertOk()
            ->assertJsonPath('attributes.relationships.password.attributes.password', 'test123');
    }

    /**
     * An application API key with only read access to server databases is authorized by
     * its own resource permissions and keeps the behaviour it has upstream.
     */
    public function testApplicationApiKeyIsUnaffected(): void
    {
        [$database, $server] = $this->createDatabase();

        /** @var ApiKey $key */
        $key = ApiKey::factory()->for($this->createAdministrator())->create([
            'key_type' => ApiKey::TYPE_APPLICATION,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_APPLICATION),
            'r_server_databases' => AdminAcl::READ,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $key->identifier . decrypt($key->token));

        $this->getJson("/api/application/servers/$server->id/databases/$database->id?include=password")
            ->assertOk()
            ->assertJsonPath('attributes.relationships.password.attributes.password', 'test123');
    }

    /**
     * The client API offers the same include, and a read scope cannot use it there either,
     * even for the owner of the server, who always holds the permission to view it.
     */
    public function testClientReadScopeCannotIncludeThePassword(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $this->createDatabase($server);

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $this->assertCredentialRefused($this->getJson("/api/client/servers/$server->uuid/databases?include=password"), OAuthScopeAcl::CLIENT_WRITE);
    }

    /**
     * Without the include, a client read scope lists the databases as before.
     */
    public function testClientReadScopeCanStillListDatabasesWithoutThePassword(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $this->createDatabase($server);

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $this->getJson("/api/client/servers/$server->uuid/databases")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.attributes.relationships.password');
    }

    /**
     * A client token that also holds the write scope can include the password.
     */
    public function testClientWriteScopeCanIncludeThePassword(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $this->createDatabase($server);

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ, OAuthScopeAcl::CLIENT_WRITE]);

        $this->getJson("/api/client/servers/$server->uuid/databases?include=password")
            ->assertOk()
            ->assertJsonPath('data.0.attributes.relationships.password.attributes.password', 'test123');
    }

    private function assertCredentialRefused(TestResponse $response, string $scope = OAuthScopeAcl::ADMIN_WRITE): void
    {
        $response->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('errors.0.code', 'AccessDeniedHttpException');

        $this->assertStringContainsString($scope, $response->json('errors.0.detail'));
    }

    /**
     * @return array{0: Database, 1: Server}
     */
    private function createDatabase(?Server $server = null): array
    {
        $server ??= $this->createServerModel();

        /** @var Database $database */
        $database = Database::factory()->create([
            'server_id' => $server->id,
            'database_host_id' => DatabaseHost::factory()->create()->id,
        ]);

        return [$database, $server];
    }

    private function createAdministrator(): User
    {
        /** @var User $user */
        $user = User::factory()->create(['root_admin' => true]);

        return $user;
    }
}
