<?php

namespace Pterodactyl\Tests\Unit\Services\Acl\Api;

use Pterodactyl\Models\User;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

class OAuthScopeAclTest extends TestCase
{
    /**
     * The authorization server offers four scopes and nothing else.
     */
    public function testOnlyFourScopesAreDefined(): void
    {
        $scopes = OAuthScopeAcl::scopes();

        $this->assertSame([
            OAuthScopeAcl::CLIENT_READ,
            OAuthScopeAcl::CLIENT_WRITE,
            OAuthScopeAcl::ADMIN_READ,
            OAuthScopeAcl::ADMIN_WRITE,
        ], array_keys($scopes));

        foreach ($scopes as $description) {
            $this->assertNotEmpty($description);
        }
    }

    /**
     * The administrative scopes, and the wildcard that contains them, are the ones
     * that may only be issued to an administrator.
     */
    public function testAdministrativeScopesAreIdentified(): void
    {
        $this->assertTrue(OAuthScopeAcl::requiresAdministrator([OAuthScopeAcl::ADMIN_READ]));
        $this->assertTrue(OAuthScopeAcl::requiresAdministrator([OAuthScopeAcl::ADMIN_WRITE]));
        $this->assertTrue(OAuthScopeAcl::requiresAdministrator(['client:read', '*']));

        $this->assertFalse(OAuthScopeAcl::requiresAdministrator([]));
        $this->assertFalse(OAuthScopeAcl::requiresAdministrator([OAuthScopeAcl::CLIENT_READ, OAuthScopeAcl::CLIENT_WRITE]));
    }

    /**
     * Passport hands the guard a token object that knows its own scopes, so that is
     * what gets asked whenever it is available.
     */
    public function testTokenIsAskedAboutItsOwnScopes(): void
    {
        $token = $this->token([OAuthScopeAcl::CLIENT_READ]);

        $this->assertTrue(OAuthScopeAcl::tokenCan($token, OAuthScopeAcl::CLIENT_READ));
        $this->assertFalse(OAuthScopeAcl::tokenCan($token, OAuthScopeAcl::CLIENT_WRITE));
    }

    /**
     * A token that cannot answer for itself falls back to the raw scope list.
     */
    public function testGrantedScopeListIsUsedAsAFallback(): void
    {
        $token = (object) ['scopes' => [OAuthScopeAcl::CLIENT_READ]];

        $this->assertTrue(OAuthScopeAcl::tokenCan($token, OAuthScopeAcl::CLIENT_READ));
        $this->assertFalse(OAuthScopeAcl::tokenCan($token, OAuthScopeAcl::CLIENT_WRITE));

        $wildcard = (object) ['scopes' => ['*']];

        $this->assertTrue(OAuthScopeAcl::tokenCan($wildcard, OAuthScopeAcl::ADMIN_WRITE));

        $this->assertFalse(OAuthScopeAcl::tokenCan(null, OAuthScopeAcl::CLIENT_READ));
    }

    /**
     * The application API permission levels map onto the two administrative scopes.
     */
    public function testPermissionLevelsMapOntoAdministrativeScopes(): void
    {
        $user = $this->user(true);
        $read = $this->token([OAuthScopeAcl::ADMIN_READ]);
        $write = $this->token([OAuthScopeAcl::ADMIN_WRITE]);

        $this->assertTrue(OAuthScopeAcl::check($user, $read, AdminAcl::RESOURCE_USERS, AdminAcl::READ));
        $this->assertFalse(OAuthScopeAcl::check($user, $read, AdminAcl::RESOURCE_USERS, AdminAcl::WRITE));

        $this->assertTrue(OAuthScopeAcl::check($user, $write, AdminAcl::RESOURCE_USERS, AdminAcl::WRITE));
        $this->assertFalse(OAuthScopeAcl::check($user, $write, AdminAcl::RESOURCE_USERS, AdminAcl::READ));
    }

    /**
     * A request that declares no permission level is refused, matching how AdminAcl
     * treats an API key.
     */
    public function testRequestWithoutAPermissionLevelIsRefused(): void
    {
        $token = $this->token([OAuthScopeAcl::ADMIN_READ, OAuthScopeAcl::ADMIN_WRITE]);

        $this->assertFalse(OAuthScopeAcl::check($this->user(true), $token, AdminAcl::RESOURCE_USERS, AdminAcl::NONE));
    }

    /**
     * The application API is closed to an account that is not an administrator, no
     * matter what the token was granted.
     */
    public function testNonAdministratorIsAlwaysRefused(): void
    {
        $token = $this->token([OAuthScopeAcl::ADMIN_READ, OAuthScopeAcl::ADMIN_WRITE, '*']);

        $this->assertFalse(OAuthScopeAcl::check($this->user(false), $token, AdminAcl::RESOURCE_USERS, AdminAcl::READ));
        $this->assertFalse(OAuthScopeAcl::check($this->user(false), $token, AdminAcl::RESOURCE_USERS, AdminAcl::WRITE));
    }

    /**
     * Returns a stand-in for the access token object Passport attaches to the user.
     */
    private function token(array $scopes): object
    {
        return new class ($scopes) {
            public function __construct(private array $scopes)
            {
            }

            public function can(string $scope): bool
            {
                return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
            }
        };
    }

    /**
     * Returns a user model that has not been persisted to the database.
     */
    private function user(bool $admin): User
    {
        /** @var User $user */
        $user = User::factory()->make(['root_admin' => $admin]);

        return $user;
    }
}
