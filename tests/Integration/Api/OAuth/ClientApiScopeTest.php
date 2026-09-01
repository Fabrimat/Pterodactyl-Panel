<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Illuminate\Http\Response;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

class ClientApiScopeTest extends OAuthIntegrationTestCase
{
    /**
     * The client API is the widest surface an OAuth token can reach, so this asserts
     * that a request authenticated by the Passport guard survives every middleware in
     * the "api" and "client-api" groups. It also proves that the User model does not
     * need Passport's HasApiTokens trait, which would collide with the Sanctum trait
     * the model already uses.
     */
    public function testOAuthTokenTraversesTheEntireApiMiddlewareChain(): void
    {
        [$user] = $this->generateTestAccount();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $this->getJson('/api/client')->assertOk();
    }

    /**
     * A read scope is enough for a GET request.
     */
    public function testReadScopeCanReadServerDetails(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $this->getJson("/api/client/servers/$server->uuid")
            ->assertOk()
            ->assertJsonPath('attributes.uuid', $server->uuid);
    }

    /**
     * A read scope must not be usable for anything that changes state, even though the
     * user themselves has permission to perform the action.
     */
    public function testReadScopeCannotPerformWriteRequests(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $this->postJson("/api/client/servers/$server->uuid/schedules", $this->scheduleAttributes())
            ->assertStatus(Response::HTTP_FORBIDDEN);

        $this->assertDatabaseMissing('schedules', ['server_id' => $server->id]);
    }

    /**
     * A write scope is required for anything that is not a GET or HEAD request.
     */
    public function testWriteScopeCanPerformWriteRequests(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_WRITE]);

        $this->postJson("/api/client/servers/$server->uuid/schedules", $this->scheduleAttributes())
            ->assertOk();

        $this->assertDatabaseHas('schedules', ['server_id' => $server->id, 'name' => 'Test Schedule']);
    }

    /**
     * A write scope does not imply a read scope, the two are granted independently.
     */
    public function testWriteScopeCannotPerformReadRequests(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_WRITE]);

        $this->getJson("/api/client/servers/$server->uuid")->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * A token that only carries the application API scopes has no business on the
     * client API and must be refused.
     */
    public function testTokenWithoutClientScopesIsRefused(): void
    {
        [$user] = $this->generateTestAccount();

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::ADMIN_READ, OAuthScopeAcl::ADMIN_WRITE]);

        $this->getJson('/api/client')->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * The two factor requirement is enforced on API requests for every authentication
     * method, so an account that is locked out of the Panel is locked out of its OAuth
     * tokens as well.
     */
    public function testOAuthTokenIsRefusedWhenTwoFactorEnrollmentIsPending(): void
    {
        [$user] = $this->generateTestAccount();
        $user->update(['use_totp' => false, 'require_2fa' => true]);

        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $this->getJson('/api/client')->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    /**
     * A client API key is not scoped and must keep working exactly as it did before
     * the OAuth guard was added to the API middleware group.
     */
    public function testClientApiKeyIsUnaffectedByScopeEnforcement(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $this->actingAsApiKeyUser($user);

        $this->getJson('/api/client')->assertOk();
        $this->getJson("/api/client/servers/$server->uuid")->assertOk();
        $this->postJson("/api/client/servers/$server->uuid/schedules", $this->scheduleAttributes())->assertOk();
    }

    /**
     * A session authenticated request carries a transient token and must also be left
     * alone by the scope enforcement.
     */
    public function testSessionRequestIsUnaffectedByScopeEnforcement(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $this->actingAs($user);

        $this->getJson('/api/client')->assertOk();
        $this->postJson("/api/client/servers/$server->uuid/schedules", $this->scheduleAttributes())->assertOk();
    }

    /**
     * Returns a valid payload for creating a schedule, used as a state changing request
     * that does not need to reach out to a daemon.
     */
    private function scheduleAttributes(): array
    {
        return [
            'name' => 'Test Schedule',
            'is_active' => false,
            'minute' => '0',
            'hour' => '*/2',
            'day_of_week' => '2',
            'month' => '1',
            'day_of_month' => '*',
        ];
    }
}
