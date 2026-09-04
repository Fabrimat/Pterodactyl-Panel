<?php

namespace Pterodactyl\Tests\Integration\Services\Backups;

use Ramsey\Uuid\Uuid;
use GuzzleHttp\Client;
use Pterodactyl\Models\Node;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\OrphanedBackup;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Nodes\NodeFeatureService;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Services\Backups\BorgConfigurationService;
use Pterodactyl\Services\Backups\DeleteOrphanedBackupService;
use Pterodactyl\Repositories\Wings\BorgDaemonBackupRepository;
use Pterodactyl\Repositories\Wings\DaemonConfigurationRepository;
use Pterodactyl\Repositories\Wings\DaemonOrphanedBackupRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Covers NodeFeatureService and the gates built on it: a node's Wings must advertise
 * a fork-only feature before the operation that needs it is ever sent, or the
 * operation is refused with a message rather than left to fail against upstream Wings
 * in whatever way that daemon happens to fail - up to and including, for a borg
 * delete, stranding the archive on the node with no error and no trace at all. See
 * testDeletingABorgBackupOnANodeWithNoFeaturesThrowsAndKeepsTheBackup() below for
 * exactly that case.
 */
class BackupNodeFeatureGateTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'backups.disks.borg.repository' => 'ssh://borg@backup.example.com:22/./pterodactyl',
            'backups.disks.borg.passphrase_secret' => 'test-secret',
            'backups.disks.borg.mode' => BorgConfigurationService::MODE_INCREMENTAL,
        ]);
    }

    /**
     * This is the case that justifies the whole feature: DeleteBackupService treats a
     * 404 from the daemon as "the backup does not exist", and removes the Panel's own
     * reference to it. Against an upstream Wings a borg delete 404s - there is no borg
     * route to hit - so without this gate the row would be soft-deleted here while the
     * repository it pointed at, an entire archive under the snapshot mode, is left
     * behind on the node forever with nothing to show for it.
     */
    public function testDeletingABorgBackupOnANodeWithNoFeaturesThrowsAndKeepsTheBackup(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
        ]);

        $this->mockNodeFeatures([]);

        // Proves the gate throws before ever reaching the daemon: if it did not, this
        // borg-disk delete would build a real Guzzle client against the factory's fake
        // node address instead of failing fast here.
        $repository = $this->partialBorgRepository();
        $repository->shouldReceive('getHttpClient')->never();

        try {
            $this->app->make(DeleteBackupService::class)->handle($backup);
            $this->fail('Expected a DisplayException to be thrown.');
        } catch (DisplayException) {
            // Expected: the node advertises no features, so the borg archive is left
            // untouched instead of being silently orphaned.
        }

        $this->assertNotSoftDeleted($backup);
    }

    /**
     * The guard in InitiateBackupService::handle() has to sit above the backup
     * rotation, which runs outside of and before the transaction that creates the new
     * backup. Placed any lower, an attempt against an incompatible node would delete
     * the oldest backup to make room and only then fail to create its replacement,
     * losing one backup per attempt for as long as the node stays incompatible.
     */
    public function testCreatingABackupWithBorgAsDefaultOnANodeWithNoFeaturesThrowsBeforeRotation(): void
    {
        config(['backups.default' => Backup::ADAPTER_BORG]);

        $server = $this->createServerModel(['backup_limit' => 1]);

        /** @var Backup $oldest */
        $oldest = Backup::factory()->create(['server_id' => $server->id]);

        $this->mockNodeFeatures([]);

        $daemon = $this->mock(DaemonBackupRepository::class);
        $daemon->shouldNotReceive('setServer');

        try {
            $this->app->make(InitiateBackupService::class)->handle($server, null, true);
            $this->fail('Expected a DisplayException to be thrown.');
        } catch (DisplayException) {
            // Expected: the node advertises no features, so nothing below this guard -
            // rotation included - is allowed to run.
        }

        $this->assertNotSoftDeleted($oldest);
    }

    /**
     * Restore is the one push call site with no service of its own in front of it, so
     * it is exercised directly against the daemon repository, the same way BorgBackupTest
     * does for the happy path.
     */
    public function testRestoringABorgBackupOnANodeWithNoFeaturesThrows(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
        ]);

        $this->mockNodeFeatures([]);

        $repository = $this->partialBorgRepository();
        $repository->shouldReceive('getHttpClient')->never();

        $this->expectException(DisplayException::class);

        $repository->setServer($server)->restore($backup);
    }

    /**
     * Applies to the plain wings disk too, not just borg: both go through the same
     * node-scoped DELETE /api/backups/{uuid} route, and only the fork's Wings
     * registers it at all.
     */
    public function testDeletingAnOrphanedBackupOnANodeWithNoFeaturesThrowsAndKeepsTheRow(): void
    {
        $node = Node::factory()->for(Location::factory())->create();
        $backup = OrphanedBackup::query()->create($this->orphanedBackupPayload([
            'disk' => Backup::ADAPTER_WINGS,
            'node_id' => $node->id,
        ]));

        $this->mockNodeFeatures([]);

        $daemon = $this->mock(DaemonOrphanedBackupRepository::class);
        $daemon->shouldNotReceive('setNode');

        try {
            $this->app->make(DeleteOrphanedBackupService::class)->handle($backup);
            $this->fail('Expected a DisplayException to be thrown.');
        } catch (DisplayException) {
            // Expected: the node advertises no features, so the row's stored data is
            // left untouched.
        }

        $this->assertDatabaseHas('orphaned_backups', ['id' => $backup->id]);
    }

    /**
     * An unreachable daemon is not evidence of an old one - the caller's own request
     * would have failed on the exact same socket a moment later - so it must surface
     * as the plain connection failure it is, never as the unsupported-feature message.
     */
    public function testAnUnreachableDaemonSurfacesTheConnectionExceptionRatherThanTheFeatureGate(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
        ]);

        $configuration = $this->mock(DaemonConfigurationRepository::class);
        $configuration->shouldReceive('setNode')->andReturnSelf();
        $configuration->shouldReceive('getSystemInformation')->andThrow(
            new DaemonConnectionException(new TransferException('Connection refused'))
        );

        // Without this the test proves nothing about the gate: delete the guard from
        // restore() and the real request would fail against the factory's address with
        // the very same exception, leaving this green while the gate was gone.
        $repository = $this->partialBorgRepository();
        $repository->shouldReceive('getHttpClient')->never();

        $this->expectException(DaemonConnectionException::class);

        $repository->setServer($server)->restore($backup);
    }

    /**
     * The other side of every guard above: a node that advertises both features must
     * let every one of these operations proceed exactly as it did before this feature
     * existed.
     */
    public function testANodeAdvertisingBothFeaturesLetsEveryGatedOperationProceed(): void
    {
        config(['backups.default' => Backup::ADAPTER_BORG]);

        $this->mockNodeFeatures([
            NodeFeatureService::FEATURE_BORG,
            NodeFeatureService::FEATURE_ORPHANED_BACKUP_DELETE,
        ]);

        $server = $this->createServerModel(['backup_limit' => 1]);

        $client = \Mockery::mock(Client::class);
        $client->shouldReceive('post')->andReturn(new Response());
        $client->shouldReceive('delete')->andReturn(new Response());

        $repository = $this->partialBorgRepository();
        $repository->shouldReceive('getHttpClient')->andReturn($client);

        $backup = $this->app->make(InitiateBackupService::class)->handle($server);
        $this->assertSame(Backup::ADAPTER_BORG, $backup->disk);

        $repository->setServer($server)->restore($backup);

        $this->app->make(DeleteBackupService::class)->handle($backup);
        $this->assertSoftDeleted($backup);

        $daemonOrphaned = $this->mock(DaemonOrphanedBackupRepository::class);
        $daemonOrphaned->expects('setNode')->andReturnSelf();
        $daemonOrphaned->expects('delete')->andReturn(new Response());

        $orphan = OrphanedBackup::query()->create($this->orphanedBackupPayload([
            'disk' => Backup::ADAPTER_WINGS,
            'node_id' => $server->node->id,
        ]));

        $this->app->make(DeleteOrphanedBackupService::class)->handle($orphan);
        $this->assertDatabaseMissing('orphaned_backups', ['id' => $orphan->id]);
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

    /**
     * Builds a partial mock of the real BorgDaemonBackupRepository with only its HTTP
     * client swappable, exactly like BorgBackupTest::mockBorgDaemonRepository() - so
     * that setServer(), setBackupAdapter() and the feature gate ahead of every borg
     * branch all run for real, and binds it in place of DaemonBackupRepository.
     */
    private function partialBorgRepository(): \Mockery\MockInterface
    {
        $repository = \Mockery::mock(BorgDaemonBackupRepository::class, [$this->app])->makePartial();
        $this->app->instance(DaemonBackupRepository::class, $repository);

        return $repository;
    }

    private function orphanedBackupPayload(array $overrides = []): array
    {
        return array_merge([
            'backup_uuid' => Uuid::uuid4()->toString(),
            'server_uuid' => Uuid::uuid4()->toString(),
            'server_name' => 'node-feature-gate-test-server',
            'node_id' => null,
            'disk' => Backup::ADAPTER_WINGS,
            'name' => 'backup.tar.gz',
            'bytes' => 1024,
            'borg_repository' => null,
            'backup_created_at' => now(),
            'orphaned_at' => now(),
        ], $overrides);
    }
}
