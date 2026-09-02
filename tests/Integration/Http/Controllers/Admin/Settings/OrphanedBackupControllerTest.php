<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Admin\Settings;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\OrphanedBackup;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Extensions\Filesystem\S3Filesystem;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Repositories\Wings\DaemonOrphanedBackupRepository;

class OrphanedBackupControllerTest extends HttpTestCase
{
    // IntegrationTestCase sets $connectionsToTransact but does not use this trait, so
    // nothing a test built on it writes is rolled back. Every orphaned backup row
    // created below would otherwise survive into the next test in this class.
    use DatabaseTransactions;

    /**
     * An admin browsing this page is a browser, not an API client. The JSON Accept
     * header IntegrationTestCase sends by default would turn any validation or routing
     * failure into a 422 document instead of the page or redirect these tests assert
     * against.
     */
    protected $defaultHeaders = ['Accept' => 'text/html'];

    public function testIndexListsOrphanedBackups(): void
    {
        $backup = OrphanedBackup::query()->create($this->payload());

        $this->actingAsAdmin()
            ->get('/admin/settings/backups/orphaned')
            ->assertOk()
            ->assertSee($backup->server_name)
            ->assertSee($backup->backup_uuid);
    }

    public function testDeleteRemovesAnS3BackupAndItsRow(): void
    {
        $backup = OrphanedBackup::query()->create($this->payload([
            'disk' => Backup::ADAPTER_AWS_S3,
            'node_id' => null,
        ]));

        $manager = $this->mock(BackupManager::class);
        $adapter = $this->mock(S3Filesystem::class);

        $manager->expects('adapter')->with(Backup::ADAPTER_AWS_S3)->andReturn($adapter);
        $adapter->expects('getBucket')->andReturn('foobar');
        $adapter->expects('getClient->deleteObject')->with([
            'Bucket' => 'foobar',
            'Key' => sprintf('%s/%s.tar.gz', $backup->server_uuid, $backup->backup_uuid),
        ]);

        $this->actingAsAdmin()
            ->delete("/admin/settings/backups/orphaned/{$backup->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('orphaned_backups', ['id' => $backup->id]);
    }

    public function testForgetRemovesOnlyThePanelRowWithoutContactingAnyNode(): void
    {
        $backup = OrphanedBackup::query()->create($this->payload());

        $daemon = $this->mock(DaemonOrphanedBackupRepository::class);
        $daemon->shouldNotReceive('setNode');

        $this->actingAsAdmin()
            ->post("/admin/settings/backups/orphaned/{$backup->id}/forget")
            ->assertNoContent();

        $this->assertDatabaseMissing('orphaned_backups', ['id' => $backup->id]);
    }

    /**
     * A borg or wings row whose node no longer exists cannot offer Delete at all -
     * Forget has to be the only action rendered for it.
     */
    public function testDeleteIsNotOfferedWhenTheNodeNoLongerExists(): void
    {
        $backup = OrphanedBackup::query()->create($this->payload([
            'disk' => Backup::ADAPTER_WINGS,
            'node_id' => null,
        ]));

        $this->actingAsAdmin()
            ->get('/admin/settings/backups/orphaned')
            ->assertOk()
            ->assertSee($backup->backup_uuid)
            ->assertSee('stored data cannot be removed from here');
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(User::factory()->admin()->create());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'backup_uuid' => Uuid::uuid4()->toString(),
            'server_uuid' => Uuid::uuid4()->toString(),
            'server_name' => 'orphaned-controller-test-server',
            'node_id' => null,
            'disk' => Backup::ADAPTER_WINGS,
            'name' => 'backup.tar.gz',
            'bytes' => 2048,
            'borg_repository' => null,
            'backup_created_at' => now(),
            'orphaned_at' => now(),
        ], $overrides);
    }
}
