<?php

namespace Pterodactyl\Tests\Integration\Services\Backups;

use GuzzleHttp\Client;
use Pterodactyl\Models\Node;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Permission;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Repositories\Wings\BorgDaemonBackupRepository;

class BorgBackupTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'backups.disks.borg.repository' => 'ssh://borg@backup.example.com:22/./pterodactyl',
            'backups.disks.borg.passphrase_secret' => 'test-secret',
        ]);
    }

    public function testInitiatingABackupSendsTheBorgConfigurationToTheDaemon(): void
    {
        config(['backups.default' => Backup::ADAPTER_BORG]);

        // The default server model has a backup_limit of 0, which InitiateBackupService
        // treats as "backups disabled" rather than "unlimited", so it has to be raised
        // here for the service to get as far as calling the daemon at all.
        $server = $this->createServerModel(['backup_limit' => 1]);

        $client = $this->mockBorgDaemonRepository();

        $client->expects('post')->withArgs(function (string $uri, array $options) use ($server) {
            $borg = $options['json']['borg'] ?? null;

            return $uri === "/api/servers/{$server->uuid}/backup"
                && is_array($borg)
                && $borg['repository'] === "ssh://borg@backup.example.com:22/./pterodactyl/{$server->uuid}"
                && $borg['archive'] === $options['json']['uuid']
                && $borg['passphrase'] === hash_hmac('sha256', "borg:v1:{$server->uuid}", 'test-secret');
        })->andReturn(new Response());

        $backup = $this->app->make(InitiateBackupService::class)->handle($server);

        $this->assertSame(Backup::ADAPTER_BORG, $backup->disk);
    }

    public function testDeletingABorgBackupGoesThroughTheDaemonWithTheBorgConfiguration(): void
    {
        $server = $this->createServerModel();

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
        ]);

        $client = $this->mockBorgDaemonRepository();

        // The parent implementation sends no body for a delete request at all; the borg
        // path has to, since Wings needs the repository and passphrase to remove the
        // archive. Asserting against "delete" (rather than "post") also confirms this
        // stayed on the daemon path and never touched the S3 branch.
        $client->expects('delete')->withArgs(function (string $uri, array $options) use ($server, $backup) {
            $borg = $options['json']['borg'] ?? null;

            return $uri === "/api/servers/{$server->uuid}/backup/{$backup->uuid}"
                && is_array($borg)
                && $borg['archive'] === $backup->uuid;
        })->andReturn(new Response());

        $this->app->make(DeleteBackupService::class)->handle($backup);

        $this->assertSoftDeleted($backup);
    }

    /**
     * Pins BackupManager::createBorgAdapter() being resolvable via the standard
     * create{Studly}Adapter lookup. Without it, BackupStatusController::index()
     * resolves the default adapter inside the completion transaction,
     * BackupManager::resolve() throws "Adapter [borg] is not supported", and the
     * transaction rolls back leaving the backup stuck as unsuccessful forever
     * instead of being marked complete.
     */
    public function testCompletionWebhookMarksABorgBackupSuccessfulRatherThanThrowing(): void
    {
        config(['backups.default' => Backup::ADAPTER_BORG]);

        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'is_successful' => false,
            'completed_at' => null,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->postJson("/api/remote/backups/{$backup->uuid}", [
                'successful' => true,
                'checksum_type' => 'sha1',
                'checksum' => str_repeat('a', 40),
                'size' => 1024,
            ])
            ->assertNoContent();

        $this->assertTrue($backup->refresh()->is_successful);
    }

    public function testABorgBackupCanBeDownloaded(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_BACKUP_DOWNLOAD]);

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
        ]);

        $this->actingAs($user)
            ->getJson("/api/client/servers/{$server->uuid}/backups/{$backup->uuid}/download")
            ->assertOk()
            ->assertJsonPath('object', 'signed_url');
    }

    /**
     * The push path (backup, restore, delete) already carries the borg object to
     * Wings. A download is driven by the browser instead, so Wings has to pull the
     * same object itself, off the node-authenticated remote API, at the point it
     * runs `borg export-tar`.
     */
    public function testANodeCanFetchTheBorgConfigurationForItsOwnBackup(): void
    {
        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->getJson("/api/remote/backups/{$backup->uuid}/borg")
            ->assertOk()
            ->assertJsonPath('repository', "ssh://borg@backup.example.com:22/./pterodactyl/{$server->uuid}")
            ->assertJsonPath('archive', $backup->uuid)
            ->assertJsonPath('passphrase', hash_hmac('sha256', "borg:v1:{$server->uuid}", 'test-secret'));
    }

    public function testANodeCannotFetchTheBorgConfigurationForAServerItDoesNotOwn(): void
    {
        $server = $this->createServerModel();
        $otherNode = Node::factory()->for(Location::factory())->create();

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
        ]);

        $this->withHeader('Authorization', "Bearer $otherNode->daemon_token_id." . $otherNode->getDecryptedKey())
            ->getJson("/api/remote/backups/{$backup->uuid}/borg")
            ->assertForbidden();
    }

    public function testFetchingTheBorgConfigurationForANonBorgBackupFails(): void
    {
        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_WINGS,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->getJson("/api/remote/backups/{$backup->uuid}/borg")
            ->assertStatus(400);
    }

    /**
     * Builds a partial mock of the real BorgDaemonBackupRepository with only its HTTP
     * client swapped out, so setServer(), setBackupAdapter() and the borg branches of
     * backup()/restore()/delete() all run for real and only the outbound Guzzle call
     * is intercepted, and binds it in place of DaemonBackupRepository for the test.
     *
     * @return \Mockery\MockInterface&Client
     */
    private function mockBorgDaemonRepository(): \Mockery\MockInterface
    {
        $client = \Mockery::mock(Client::class);

        $repository = \Mockery::mock(BorgDaemonBackupRepository::class, [$this->app])->makePartial();
        $repository->shouldReceive('getHttpClient')->andReturn($client);

        $this->app->instance(DaemonBackupRepository::class, $repository);

        return $client;
    }
}
