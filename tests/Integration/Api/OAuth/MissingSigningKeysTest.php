<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Pterodactyl\Models\User;
use Laravel\Passport\Passport;

class MissingSigningKeysTest extends OAuthIntegrationTestCase
{
    /**
     * Point Passport at a directory that holds no keys before the application boots.
     * That is the state of an installation which has taken this code but has not run
     * "php artisan passport:keys" yet.
     */
    public function setUp(): void
    {
        Passport::loadKeysFrom(__DIR__ . '/keys-that-do-not-exist');

        parent::setUp();
    }

    /**
     * Restore the default key location for the rest of the suite.
     */
    protected function tearDown(): void
    {
        Passport::loadKeysFrom('');

        parent::tearDown();
    }

    /**
     * The OAuth guard is consulted on every API request that Sanctum turns down, and
     * building the real one reads the public key off disk. Without the key that read
     * throws an exception the handler can only render as a 500, so the negative paths
     * of an existing installation would break. They have to keep returning a 401.
     */
    public function testUnauthenticatedRequestIsStillRejectedWithUnauthorized(): void
    {
        $this->getJson('/api/client')->assertUnauthorized();
        $this->getJson('/api/application/users')->assertUnauthorized();
    }

    /**
     * The same applies to credentials that do not match anything.
     */
    public function testInvalidCredentialsAreStillRejectedWithUnauthorized(): void
    {
        $this->withHeader('Authorization', 'Bearer ptlc_thisisnotarealapikeyatall')
            ->getJson('/api/client')
            ->assertUnauthorized();
    }

    /**
     * An API key is authenticated by Sanctum before the OAuth guard is ever reached, so
     * missing keys must not change anything about it.
     */
    public function testApiKeyAuthenticationIsUnaffected(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAsApiKeyUser($user);

        $this->getJson('/api/client')->assertOk();
    }

    /**
     * The same holds for a session authenticated request.
     */
    public function testSessionAuthenticationIsUnaffected(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/client')->assertOk();
    }
}
