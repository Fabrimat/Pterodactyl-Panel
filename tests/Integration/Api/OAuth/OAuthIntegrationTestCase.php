<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Pterodactyl\Models\User;
use Laravel\Passport\Passport;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Tests\Traits\Http\IntegrationJsonRequestAssertions;

abstract class OAuthIntegrationTestCase extends IntegrationTestCase
{
    use DatabaseTransactions;
    use IntegrationJsonRequestAssertions;

    /**
     * Authenticate as the given user using an OAuth access token that carries the
     * provided scopes. The token is attached to the "oauth" guard so that requests
     * travel the exact same path a real access token would.
     */
    protected function actingAsOAuthUser(User $user, array $scopes = []): User
    {
        Passport::actingAs($user, $scopes, 'oauth');

        return $user;
    }

    /**
     * Creates a client API key for the given user and sets the authorization header
     * to use it. This is how every existing integration point authenticates and must
     * keep behaving exactly as it did before OAuth was introduced.
     */
    protected function actingAsApiKeyUser(User $user, int $type = ApiKey::TYPE_ACCOUNT): ApiKey
    {
        /** @var ApiKey $key */
        $key = ApiKey::factory()->for($user)->create([
            'key_type' => $type,
            'identifier' => ApiKey::generateTokenIdentifier($type),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $key->identifier . decrypt($key->token));

        return $key;
    }
}
