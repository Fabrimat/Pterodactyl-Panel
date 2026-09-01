<?php

namespace Pterodactyl\Tests\Integration\Api\Mcp;

use Illuminate\Http\Response;
use Pterodactyl\Http\Controllers\Mcp\McpController;

class EnvelopeTest extends McpIntegrationTestCase
{
    public function testInitializeAdvertisesTheProtocolVersionAndCapabilities(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => McpController::PROTOCOL_VERSION],
        ])->assertOk();

        $response->assertJsonPath('result.protocolVersion', McpController::PROTOCOL_VERSION);
        $response->assertJsonPath('result.capabilities.tools.listChanged', false);
        $response->assertJsonPath('result.serverInfo.name', 'pterodactyl-panel');
        $this->assertArrayHasKey('version', $response->json('result.serverInfo'));
    }

    /**
     * A request without an "id" member is a notification. Nothing may ever be sent
     * back for one, not a result and not an error, so the only correct answer is an
     * empty 202 with no body at all.
     */
    public function testNotificationWithoutAnIdReceivesAnEmptyAcceptedResponse(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $response->assertStatus(Response::HTTP_ACCEPTED);
        $this->assertSame('', $response->getContent());
    }

    public function testUnknownMethodIsAJsonRpcErrorReturnedAtHttpOk(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'not/a/real/method'])->assertOk();

        $response->assertJsonPath('error.code', -32601);
        $this->assertArrayNotHasKey('result', $response->json());
    }

    public function testMalformedJsonIsAParseError(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->rawPost('{not valid json');

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $response->assertJsonPath('error.code', -32700);
    }

    /**
     * Batching was removed from the protocol in revision 2025-06-18. A top level JSON
     * array is a client speaking an earlier revision this server does not implement,
     * not something worth trying to answer piecemeal.
     */
    public function testATopLevelJsonArrayIsRejectedAsBatching(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->rpc([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $response->assertJsonPath('error.code', -32600);
    }

    public function testGetAndDeleteAreRefusedRegardlessOfAuthentication(): void
    {
        $this->getJson('/mcp')->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED)->assertHeader('Allow', 'POST');
        $this->deleteJson('/mcp')->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED)->assertHeader('Allow', 'POST');

        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $this->getJson('/mcp')->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED)->assertHeader('Allow', 'POST');
        $this->deleteJson('/mcp')->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED)->assertHeader('Allow', 'POST');
    }

    /**
     * An empty PHP array would be encoded as a JSON array, "[]", and a client would
     * reject that as an invalid result for "ping". It has to come back as "{}".
     */
    public function testPingReturnsAnEmptyObjectRatherThanAnEmptyArray(): void
    {
        [$user] = $this->generateTestAccount();
        $this->actingAsApiKeyUser($user);

        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertOk();

        $decoded = json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class, $decoded->result);
        $this->assertSame([], (array) $decoded->result);
    }
}
