<?php

namespace Pterodactyl\Tests\Integration\Services\Backups;

use GuzzleHttp\Client;
use Pterodactyl\Models\Node;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Permission;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Services\Nodes\NodeFeatureService;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Services\Backups\BorgConfigurationService;
use Pterodactyl\Repositories\Wings\BorgDaemonBackupRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class BorgBackupTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'backups.disks.borg.repository' => 'ssh://borg@backup.example.com:22/./pterodactyl',
            'backups.disks.borg.passphrase_secret' => 'test-secret',
            'backups.disks.borg.mode' => BorgConfigurationService::MODE_INCREMENTAL,
        ]);

        // NodeFeatureService goes through DaemonConfigurationRepository, which builds
        // its own real Guzzle client rather than going through getHttpClient() like the
        // rest of this suite mocks around - so left unbound, every borg push call site
        // below would attempt a real outbound connection to the factory-generated node.
        // A permissive double keeps this suite exercising the borg branches themselves
        // rather than the feature gate in front of them; the gate has its own coverage.
        $nodeFeatures = \Mockery::mock(NodeFeatureService::class);
        $nodeFeatures->shouldReceive('assertSupports')->andReturnNull();
        $this->app->instance(NodeFeatureService::class, $nodeFeatures);
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

        // The incremental mode is the plain per-server layout, which resolving a null
        // suffix already falls back to on its own, so nothing needs to be recorded for
        // it. This must not be a behaviour change for anyone who does not set the mode.
        $this->assertNull($backup->refresh()->borg_repository);
    }

    /**
     * Under the snapshot mode a new backup records the repository suffix it was built
     * against - a flat sibling of the server's own repository - so that it stays
     * reachable at that same path no matter what the mode is changed to afterwards.
     */
    public function testInitiatingABackupUnderTheSnapshotModeRecordsAndSendsAPerBackupRepository(): void
    {
        config([
            'backups.default' => Backup::ADAPTER_BORG,
            'backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT,
        ]);

        $server = $this->createServerModel(['backup_limit' => 1]);

        $client = $this->mockBorgDaemonRepository();

        $client->expects('post')->withArgs(function (string $uri, array $options) use ($server) {
            $borg = $options['json']['borg'] ?? null;
            $expected = "ssh://borg@backup.example.com:22/./pterodactyl/{$server->uuid}_{$options['json']['uuid']}";

            return is_array($borg) && $borg['repository'] === $expected;
        })->andReturn(new Response());

        $backup = $this->app->make(InitiateBackupService::class)->handle($server);

        $this->assertSame("{$server->uuid}_{$backup->uuid}", $backup->refresh()->borg_repository);
    }

    /**
     * borg_repository is recorded inside the same transaction that creates the backup
     * row - InitiateBackupService wraps the whole daemon call in one - so a failed
     * daemon request must roll both back together rather than leaving a backup row
     * behind with no corresponding archive on the node.
     */
    public function testAFailedDaemonRequestRollsBackTheBackupRowAndItsRecordedRepositoryTogether(): void
    {
        config([
            'backups.default' => Backup::ADAPTER_BORG,
            'backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT,
        ]);

        $server = $this->createServerModel(['backup_limit' => 1]);

        $client = $this->mockBorgDaemonRepository();
        $client->expects('post')->andThrow(new TransferException('connection refused'));

        $thrown = null;
        try {
            $this->app->make(InitiateBackupService::class)->handle($server);
        } catch (DaemonConnectionException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Expected a DaemonConnectionException to be thrown.');
        $this->assertDatabaseMissing('backups', ['server_id' => $server->id]);
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
     * A backup created before this column existed - the factory default here - has a
     * null borg_repository. That must resolve straight to the plain per-server
     * repository even once the panel has since moved to the snapshot mode, or the
     * archive it actually lives in becomes unreachable.
     */
    public function testDeletingALegacyBorgBackupResolvesToThePerServerRepositoryEvenUnderTheSnapshotMode(): void
    {
        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT]);

        $server = $this->createServerModel();

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
            'borg_repository' => null,
        ]);

        $client = $this->mockBorgDaemonRepository();

        $client->expects('delete')->withArgs(function (string $uri, array $options) use ($server) {
            $borg = $options['json']['borg'] ?? null;

            return is_array($borg) && $borg['repository'] === "ssh://borg@backup.example.com:22/./pterodactyl/{$server->uuid}";
        })->andReturn(new Response());

        $this->app->make(DeleteBackupService::class)->handle($backup);
    }

    /**
     * A recorded repository is what a backup actually was written to, so it is used
     * verbatim regardless of whatever the mode is set to by the time it is deleted.
     */
    public function testDeletingABorgBackupWithARecordedRepositoryUsesItVerbatimRegardlessOfTheCurrentMode(): void
    {
        $server = $this->createServerModel();
        $recorded = "{$server->uuid}_a-previously-taken-backup";

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
            'borg_repository' => $recorded,
        ]);

        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT]);

        $client = $this->mockBorgDaemonRepository();

        $client->expects('delete')->withArgs(function (string $uri, array $options) use ($recorded) {
            $borg = $options['json']['borg'] ?? null;

            return is_array($borg) && $borg['repository'] === "ssh://borg@backup.example.com:22/./pterodactyl/{$recorded}";
        })->andReturn(new Response());

        $this->app->make(DeleteBackupService::class)->handle($backup);
    }

    /**
     * Restore is the third push call site; it has to read the same recorded repository
     * back rather than recomputing it, exactly like delete does.
     */
    public function testRestoringABorgBackupSendsTheRecordedRepositoryToTheDaemon(): void
    {
        $server = $this->createServerModel();
        $recorded = "{$server->uuid}_a-previously-taken-backup";

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
            'borg_repository' => $recorded,
        ]);

        [$client, $repository] = $this->mockBorgDaemonRepositoryDirectly($server);

        $client->expects('post')->withArgs(function (string $uri, array $options) use ($server, $backup, $recorded) {
            $borg = $options['json']['borg'] ?? null;

            return $uri === "/api/servers/{$server->uuid}/backup/{$backup->uuid}/restore"
                && is_array($borg)
                && $borg['repository'] === "ssh://borg@backup.example.com:22/./pterodactyl/{$recorded}";
        })->andReturn(new Response());

        $repository->restore($backup);
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

    /**
     * This is the fourth call site, the one that does not go through
     * BorgDaemonBackupRepository at all - a legacy null repository has to resolve to
     * the per-server path here just as it does on the push side, even once the panel
     * has since moved to the snapshot mode.
     */
    public function testTheRemoteConfigurationEndpointResolvesALegacyNullRepositoryToThePerServerPathEvenUnderTheSnapshotMode(): void
    {
        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT]);

        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
            'borg_repository' => null,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->getJson("/api/remote/backups/{$backup->uuid}/borg")
            ->assertOk()
            ->assertJsonPath('repository', "ssh://borg@backup.example.com:22/./pterodactyl/{$server->uuid}");
    }

    public function testTheRemoteConfigurationEndpointUsesARecordedRepositoryVerbatim(): void
    {
        $server = $this->createServerModel();
        $node = $server->node;
        $recorded = "{$server->uuid}_a-previously-taken-backup";

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
            'borg_repository' => $recorded,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->getJson("/api/remote/backups/{$backup->uuid}/borg")
            ->assertOk()
            ->assertJsonPath('repository', "ssh://borg@backup.example.com:22/./pterodactyl/{$recorded}");
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

    /**
     * Same partial mock as mockBorgDaemonRepository(), but returned directly rather
     * than bound in the container, and with the server already set - restore() has no
     * service of its own to resolve the daemon repository through, unlike backup() and
     * delete() which go through InitiateBackupService and DeleteBackupService.
     *
     * @return array{0: \Mockery\MockInterface&Client, 1: BorgDaemonBackupRepository}
     */
    private function mockBorgDaemonRepositoryDirectly(Server $server): array
    {
        $client = \Mockery::mock(Client::class);

        $repository = \Mockery::mock(BorgDaemonBackupRepository::class, [$this->app])->makePartial();
        $repository->shouldReceive('getHttpClient')->andReturn($client);
        $repository->setServer($server);

        return [$client, $repository];
    }
}
