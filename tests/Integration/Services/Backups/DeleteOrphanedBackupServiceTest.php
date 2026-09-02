<?php

namespace Pterodactyl\Tests\Integration\Services\Backups;

use Ramsey\Uuid\Uuid;
use GuzzleHttp\Psr7\Request;
use Pterodactyl\Models\Node;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\OrphanedBackup;
use GuzzleHttp\Exception\ClientException;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Extensions\Filesystem\S3Filesystem;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Backups\DeleteOrphanedBackupService;
use Pterodactyl\Repositories\Wings\DaemonOrphanedBackupRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Service\Backup\OrphanedBackupNodeMissingException;

class DeleteOrphanedBackupServiceTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'backups.disks.borg.repository' => 'ssh://borg@backup.example.com:22/./pterodactyl',
            'backups.disks.borg.passphrase_secret' => 'test-secret',
        ]);
    }

    public function testS3BackupIsDeletedWithoutContactingAnyNode(): void
    {
        $backup = OrphanedBackup::query()->create($this->orphanedBackupPayload([
            'disk' => Backup::ADAPTER_AWS_S3,
            'node_id' => null,
        ]));

        $manager = $this->mock(BackupManager::class);
        $adapter = $this->mock(S3Filesystem::class);
        $daemon = $this->mock(DaemonOrphanedBackupRepository::class);

        $manager->expects('adapter')->with(Backup::ADAPTER_AWS_S3)->andReturn($adapter);
        $adapter->expects('getBucket')->andReturn('foobar');
        $adapter->expects('getClient->deleteObject')->with([
            'Bucket' => 'foobar',
            'Key' => sprintf('%s/%s.tar.gz', $backup->server_uuid, $backup->backup_uuid),
        ]);
        $daemon->shouldNotReceive('setNode');

        $this->app->make(DeleteOrphanedBackupService::class)->handle($backup);

        $this->assertDatabaseMissing('orphaned_backups', ['id' => $backup->id]);
    }

    /**
     * Wings has no route for this request yet, so today a 404 means "the route does
     * not exist", never "the backup does not exist" - and a borg body can only ever
     * get back a 400 or a 204 once the route does exist, never a 404 either. There is
     * no tolerance for it on this path: it keeps the row and surfaces the error, the
     * same as any other failure.
     */
    public function testA404FromTheNodeKeepsTheRowAndSurfacesTheError(): void
    {
        $node = Node::factory()->for(Location::factory())->create();
        $backup = OrphanedBackup::query()->create($this->orphanedBackupPayload([
            'disk' => Backup::ADAPTER_WINGS,
            'node_id' => $node->id,
        ]));

        $daemon = $this->mock(DaemonOrphanedBackupRepository::class);
        $daemon->expects('setNode')->andReturnSelf();
        $daemon->expects('delete')->andThrow(
            new DaemonConnectionException(new ClientException('', new Request('DELETE', '/'), new Response(404)))
        );

        // expectException() would make the assertion below dead code - the exception
        // unwinds this method immediately - so it is verified from an explicit catch.
        try {
            $this->app->make(DeleteOrphanedBackupService::class)->handle($backup);
            $this->fail('Expected a DaemonConnectionException to be thrown.');
        } catch (DaemonConnectionException) {
            // Expected: no status code is tolerated on this path.
        }

        $this->assertDatabaseHas('orphaned_backups', ['id' => $backup->id]);
    }

    public function testAnyOtherFailureKeepsTheRowAndSurfacesTheError(): void
    {
        $node = Node::factory()->for(Location::factory())->create();
        $backup = OrphanedBackup::query()->create($this->orphanedBackupPayload([
            'disk' => Backup::ADAPTER_WINGS,
            'node_id' => $node->id,
        ]));

        $daemon = $this->mock(DaemonOrphanedBackupRepository::class);
        $daemon->expects('setNode')->andReturnSelf();
        $daemon->expects('delete')->andThrow(
            new DaemonConnectionException(new ClientException('', new Request('DELETE', '/'), new Response(500)))
        );

        // expectException() would make the assertion below dead code - the exception
        // unwinds this method immediately - so it is verified from an explicit catch.
        try {
            $this->app->make(DeleteOrphanedBackupService::class)->handle($backup);
            $this->fail('Expected a DaemonConnectionException to be thrown.');
        } catch (DaemonConnectionException) {
            // Expected: no tolerance on this path, and this is a 500.
        }

        $this->assertDatabaseHas('orphaned_backups', ['id' => $backup->id]);
    }

    /**
     * A borg or wings row whose node has since been deleted has nowhere left to send a
     * delete request to at all - Forget is the only remaining option for it, and this
     * guard is what stops the action being reached any other way.
     */
    public function testANullNodeIdCannotBeDeletedForANonS3Row(): void
    {
        $backup = OrphanedBackup::query()->create($this->orphanedBackupPayload([
            'disk' => Backup::ADAPTER_WINGS,
            'node_id' => null,
        ]));

        try {
            $this->app->make(DeleteOrphanedBackupService::class)->handle($backup);
            $this->fail('Expected an OrphanedBackupNodeMissingException to be thrown.');
        } catch (OrphanedBackupNodeMissingException) {
            // Expected: there is no node left to send the delete request to.
        }

        $this->assertDatabaseHas('orphaned_backups', ['id' => $backup->id]);
    }

    private function orphanedBackupPayload(array $overrides = []): array
    {
        return array_merge([
            'backup_uuid' => Uuid::uuid4()->toString(),
            'server_uuid' => Uuid::uuid4()->toString(),
            'server_name' => 'orphaned-test-server',
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
