<?php

namespace Pterodactyl\Tests\Integration\Api\Mcp;

use Illuminate\Support\Str;
use Pterodactyl\Services\Mcp\EndpointRegistry;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Pterodactyl\Http\Middleware\Api\Client\AuthenticateOAuthScopes;

class ToolCatalogTest extends McpIntegrationTestCase
{
    private EndpointRegistry $registry;

    public function setUp(): void
    {
        parent::setUp();

        $this->registry = $this->app->make(EndpointRegistry::class);
    }

    public function testAdministratorSeesEveryToolInTheTable(): void
    {
        [$user] = $this->generateTestAccount();
        $user->update(['root_admin' => true]);
        $this->actingAsApiKeyUser($user);

        $this->assertEqualsCanonicalizing(array_keys($this->registry->all()), $this->toolNames());
    }

    /**
     * A non-admin account key must see exactly the client rows of the table and none
     * of the application ones: advertising them would only produce a wall of 403s,
     * since AuthenticateApplicationUser refuses anybody who is not an administrator.
     */
    public function testNonAdministratorSeesOnlyClientToolsAndNoAdminTools(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $expected = array_keys(array_filter(
            $this->registry->all(),
            fn (array $row) => ($row['api'] ?? null) !== 'application'
        ));

        $names = $this->toolNames();
        $this->assertNotEmpty($names);
        $this->assertEqualsCanonicalizing($expected, $names);
        $this->assertCount(0, array_filter($names, fn ($name) => str_starts_with($name, 'panel_admin_')));
    }

    public function testReadOnlyOAuthTokenSeesOnlyClientReadTools(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        $names = $this->toolNames();
        $this->assertNotEmpty($names);

        $applicationTools = array_keys(array_filter(
            $this->registry->all(),
            fn (array $row) => ($row['api'] ?? null) === 'application'
        ));
        $writeTools = array_keys(array_filter(
            $this->registry->all(),
            fn (array $row) => strtoupper($row['method'] ?? 'GET') !== 'GET'
        ));

        $this->assertEmpty(array_intersect($names, $applicationTools));
        $this->assertEmpty(array_intersect($names, $writeTools));
    }

    /**
     * The routes that hand out or replace account credentials are refused for OAuth
     * tokens no matter which scopes they carry, so they must never be advertised to
     * one, and a client that calls one anyway by name must still be turned away.
     */
    public function testAccountCredentialToolsAreAbsentFromTheListingAndRefusedOnCall(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ, OAuthScopeAcl::CLIENT_WRITE]);

        $protected = $this->protectedToolNames();
        $this->assertNotEmpty($protected);

        $this->assertEmpty(array_intersect($this->toolNames(), $protected));

        $response = $this->callTool(reset($protected))->assertOk();
        $response->assertJsonPath('error.code', -32602);
        $this->assertArrayNotHasKey('result', $response->json());
    }

    /**
     * tools/call resolves a tool name through the exact same filter tools/list uses
     * to build the listing. This must hold even for a tool that was never listed in
     * the first place, so it is asserted directly rather than by first checking that
     * the name is missing from tools/list.
     */
    public function testCallingAnAdminToolByNameIsRefusedForANonAdministrator(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $adminTool = array_key_first(array_filter(
            $this->registry->all(),
            fn (array $row) => ($row['api'] ?? null) === 'application'
        ));

        $response = $this->callTool($adminTool)->assertOk();
        $response->assertJsonPath('error.code', -32602);
        $this->assertArrayNotHasKey('result', $response->json());
    }

    public function testCallingAWriteToolByNameIsRefusedForAReadOnlyOAuthToken(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsOAuthUser($user, [OAuthScopeAcl::CLIENT_READ]);

        // Excludes the protected credential routes so this isolates the scope check:
        // those are refused for an entirely different reason, covered separately above.
        $protected = $this->protectedToolNames();
        $writeTool = array_key_first(array_filter(
            $this->registry->all(),
            fn (array $row, string $name) => ($row['api'] ?? null) !== 'application'
                && strtoupper($row['method'] ?? 'GET') !== 'GET'
                && !in_array($name, $protected, true),
            ARRAY_FILTER_USE_BOTH
        ));

        $response = $this->callTool($writeTool)->assertOk();
        $response->assertJsonPath('error.code', -32602);
        $this->assertArrayNotHasKey('result', $response->json());
    }

    /**
     * A client that mistyped a tool name gets a different message than one that
     * asked for a tool it holds no permission to use, so it can tell the two apart
     * rather than assuming every failure means "try a different name".
     */
    public function testAnUnknownToolNameIsDistinguishableFromAForbiddenOne(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $adminTool = array_key_first(array_filter(
            $this->registry->all(),
            fn (array $row) => ($row['api'] ?? null) === 'application'
        ));

        $forbidden = $this->callTool($adminTool)->assertOk()->json('error.message');
        $unknown = $this->callTool('panel_this_tool_does_not_exist')->assertOk()->json('error.message');

        $this->assertStringContainsString('is not available', $forbidden);
        $this->assertStringContainsString('no tool named', $unknown);
        $this->assertNotSame($forbidden, $unknown);
    }

    /**
     * The rows an OAuth token is refused regardless of scope, derived from the same
     * constant the client API middleware enforces so the two cannot drift apart.
     *
     * @return string[]
     */
    private function protectedToolNames(): array
    {
        return array_keys(array_filter($this->registry->all(), function (array $row) {
            $uri = ltrim(EndpointRegistry::basePath($row), '/') . ($row['path'] ?? '');

            return Str::startsWith($uri, AuthenticateOAuthScopes::PROTECTED_ROUTES);
        }));
    }
}
