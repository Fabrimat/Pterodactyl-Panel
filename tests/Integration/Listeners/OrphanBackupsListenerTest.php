<?php

namespace Pterodactyl\Tests\Integration\Listeners;

use Mockery\MockInterface;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Backup;
use Illuminate\Support\Facades\Event;
use Pterodactyl\Models\OrphanedBackup;
use Pterodactyl\Events\Server\Deleting;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class OrphanBackupsListenerTest extends IntegrationTestCase
{
    private MockInterface $daemonServerRepository;

    private static ?string $defaultLogger;

    /**
     * Stub out the daemon calls ServerDeletionService makes so the deletion itself
     * always succeeds and the listener under test is the only thing being exercised.
     */
    public function setUp(): void
    {
        parent::setUp();

        self::$defaultLogger = config('logging.default');
        config()->set('logging.default', 'null');

        $this->daemonServerRepository = \Mockery::mock(DaemonServerRepository::class);
        $this->app->instance(DaemonServerRepository::class, $this->daemonServerRepository);
        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();
    }

    protected function tearDown(): void
    {
        config()->set('logging.default', self::$defaultLogger);
        self::$defaultLogger = null;

        parent::tearDown();
    }

    public function testDeletingAServerOrphansItsSuccessfulCompletedBackups(): void
    {
        $server = $this->createServerModel();

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_BORG,
            'borg_repository' => 'incremental/2026/01',
            'bytes' => 123456,
        ]);

        $this->getService()->handle($server);

        $this->assertDatabaseHas('orphaned_backups', [
            'backup_uuid' => $backup->uuid,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'node_id' => $server->node_id,
            'disk' => Backup::ADAPTER_BORG,
            'name' => $backup->name,
            'bytes' => 123456,
            'borg_repository' => 'incremental/2026/01',
        ]);
    }

    /**
     * backups.bytes is an unsignedBigInteger; orphaned_backups.bytes has to match it
     * rather than the plain, signed 32-bit integer that caps out around 2 GiB, or an
     * ordinary large backup would fail this insert - inside ServerDeletionService's
     * transaction, after Wings has already been told to delete the server.
     */
    public function testALargeBackupSizeSurvivesTheListenerIntact(): void
    {
        $server = $this->createServerModel();

        $largeSize = 5_000_000_000; // exceeds the signed 32-bit integer range

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'bytes' => $largeSize,
        ]);

        $this->getService()->handle($server);

        $this->assertSame($largeSize, OrphanedBackup::query()->where('backup_uuid', $backup->uuid)->firstOrFail()->bytes);
    }

    /**
     * A failed backup, a still-running one and a soft-deleted one all have nothing
     * worth an orphan row: the first two never finished writing anything, and the
     * last one already had its data removed by DeleteBackupService.
     */
    public function testFailedIncompleteAndSoftDeletedBackupsAreNotOrphaned(): void
    {
        $server = $this->createServerModel();

        Backup::factory()->create([
            'server_id' => $server->id,
            'is_successful' => false,
        ]);

        Backup::factory()->create([
            'server_id' => $server->id,
            'is_successful' => true,
            'completed_at' => null,
        ]);

        $softDeleted = Backup::factory()->create([
            'server_id' => $server->id,
        ]);
        $softDeleted->delete();

        $this->getService()->handle($server);

        $this->assertSame(0, OrphanedBackup::query()->where('server_uuid', $server->uuid)->count());
    }

    /**
     * The listener runs inside ServerDeletionService's own transaction. If anything
     * after it fails, the whole transaction - including the orphan rows the listener
     * already wrote - rolls back along with the server deletion itself.
     */
    public function testARolledBackServerDeletionLeavesNoOrphanRows(): void
    {
        $server = $this->createServerModel();
        Backup::factory()->create(['server_id' => $server->id]);

        Event::listen(Deleting::class, function () {
            throw new \RuntimeException('simulated failure after the backups were orphaned');
        });

        // expectException() would make everything below this call dead code - the
        // exception unwinds the test method immediately - so the rollback itself is
        // verified from inside an explicit catch instead.
        try {
            $this->getService()->handle($server);
            $this->fail('Expected handle() to rethrow the exception raised by the second listener.');
        } catch (\RuntimeException) {
            // Expected: the transaction rolled back and rethrew it.
        }

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertSame(0, OrphanedBackup::query()->where('server_uuid', $server->uuid)->count());
    }

    /**
     * The node a backup was orphaned from can be removed later on its own; the
     * foreign key on orphaned_backups.node_id sets it to null rather than deleting
     * the row, since the row is the only remaining record the backup ever existed.
     */
    public function testDeletingTheNodeLeavesTheOrphanRowInPlaceWithNodeIdNull(): void
    {
        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Backup $backup */
        $backup = Backup::factory()->create(['server_id' => $server->id]);

        $this->getService()->handle($server);

        $orphan = OrphanedBackup::query()->where('backup_uuid', $backup->uuid)->firstOrFail();
        $this->assertSame($node->id, $orphan->node_id);

        Node::query()->where('id', $node->id)->delete();

        $this->assertNull($orphan->refresh()->node_id);
    }

    private function getService(): ServerDeletionService
    {
        return $this->app->make(ServerDeletionService::class);
    }
}
