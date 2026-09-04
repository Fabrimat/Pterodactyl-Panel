<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Files;

use Illuminate\Http\Response;
use Pterodactyl\Models\Permission;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Services\Nodes\NodeFeatureService;
use Pterodactyl\Repositories\Wings\DaemonConfigurationRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

/**
 * Covers the folder-download feature gate FileController::download() runs ahead of
 * minting a node token. A plain file download must never consult a node's advertised
 * features at all, so it keeps working against an upstream Wings exactly as it always
 * has. A folder download, by contrast, is refused up front on a node whose Wings does
 * not advertise the capability, rather than handing the browser a signed URL for a
 * route the daemon has no handler for at all.
 */
class DownloadFileTest extends ClientApiIntegrationTestCase
{
    public function testFileDownloadIsNeverGatedOnTheFolderDownloadFeature(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_FILE_READ_CONTENT]);

        $this->mockNodeFeatures([]);

        $this->actingAs($user)
            ->getJson($this->link($server, 'files/download') . '?file=/test.txt')
            ->assertOk()
            ->assertJsonStructure(['object', 'attributes' => ['url']]);
    }

    /**
     * Covers both spellings the "directory" flag can arrive as: "true", the literal
     * axios produces for the JS boolean the frontend actually sends, and "1", the
     * legacy numeric spelling. Both must keep working - if a "boolean" validation rule
     * is ever added for this flag, "true" 422s here and fails this test, because that
     * rule strict-compares against [true, false, 0, 1, '0', '1'] and "true" is not in
     * that list.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('directoryFlagDataProvider')]
    public function testFolderDownloadOnANodeAdvertisingTheFeatureMintsTheToken(string $directoryFlag): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_FILE_READ_CONTENT]);

        $this->mockNodeFeatures([NodeFeatureService::FEATURE_FOLDER_DOWNLOAD]);

        $this->actingAs($user)
            ->getJson($this->link($server, 'files/download') . '?file=/&directory=' . $directoryFlag)
            ->assertOk()
            ->assertJsonStructure(['object', 'attributes' => ['url']]);
    }

    /**
     * The ordering matters: the gate has to run before the token is minted, or a
     * folder download against an incompatible node would still hand the browser a
     * working signed URL for a route upstream Wings has never heard of.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('directoryFlagDataProvider')]
    public function testFolderDownloadOnANodeWithNoFeaturesThrowsBeforeMintingAToken(string $directoryFlag): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_FILE_READ_CONTENT]);

        $this->mockNodeFeatures([]);

        $jwt = $this->mock(NodeJWTService::class);
        $jwt->shouldNotReceive('handle');

        $this->actingAs($user)
            ->getJson($this->link($server, 'files/download') . '?file=/&directory=' . $directoryFlag)
            ->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    public static function directoryFlagDataProvider(): array
    {
        return [
            'the value the browser actually sends' => ['true'],
            'the legacy numeric spelling' => ['1'],
        ];
    }

    /**
     * Binds a mock of DaemonConfigurationRepository - the real HTTP boundary
     * NodeFeatureService goes through - so that NodeFeatureService itself runs for
     * real against a canned response instead of attempting a real outbound connection
     * to a factory-generated node address.
     */
    private function mockNodeFeatures(array $features): void
    {
        $configuration = $this->mock(DaemonConfigurationRepository::class);
        $configuration->shouldReceive('setNode')->andReturnSelf();
        $configuration->shouldReceive('getSystemInformation')->andReturn(['features' => $features]);
    }
}
