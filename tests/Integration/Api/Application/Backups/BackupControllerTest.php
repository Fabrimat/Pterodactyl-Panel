<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Backups;

use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Extensions\Filesystem\S3Filesystem;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class BackupControllerTest extends ApplicationApiIntegrationTestCase
{
    public function testBackupsCanBeFilteredByServerAndDiskAndSorted(): void
    {
        $serverOne = $this->createServerModel();
        $serverTwo = $this->createServerModel();

        $wingsBackup = Backup::factory()->create([
            'server_id' => $serverOne->id,
            'disk' => Backup::ADAPTER_WINGS,
            'bytes' => 1024,
        ]);
        Backup::factory()->create([
            'server_id' => $serverTwo->id,
            'disk' => Backup::ADAPTER_AWS_S3,
            'bytes' => 2048,
        ]);

        $response = $this->getJson('/api/application/backups?filter[server_id]=' . $serverOne->id);
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.attributes.uuid', $wingsBackup->uuid);

        $response = $this->getJson('/api/application/backups?filter[disk]=' . Backup::ADAPTER_AWS_S3);
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.attributes.disk', Backup::ADAPTER_AWS_S3);

        $response = $this->getJson('/api/application/backups?sort=-bytes');
        $response->assertOk();
        $response->assertJsonPath('data.0.attributes.bytes', 2048);
        $response->assertJsonPath('data.1.attributes.bytes', 1024);
    }

    public function testIncludeServerEmbedsTheOwningServer(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create(['server_id' => $server->id]);

        $response = $this->getJson('/api/application/backups/' . $backup->id . '?include=server');
        $response->assertOk();
        $response->assertJsonPath('attributes.relationships.server.attributes.uuid', $server->uuid);
    }

    public function testViewSingleBackup(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create(['server_id' => $server->id]);

        $this->getJson('/api/application/backups/' . $backup->id)
            ->assertOk()
            ->assertJsonPath('attributes.uuid', $backup->uuid);
    }

    public function testLockedBackupCannotBeDeleted(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'is_locked' => true,
        ]);

        $this->delete('/api/application/backups/' . $backup->id)
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertDatabaseHas('backups', ['id' => $backup->id, 'deleted_at' => null]);
    }

    public function testUnlockedBackupIsDeletedThroughTheDeleteBackupService(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_AWS_S3,
        ]);

        $manager = $this->mock(BackupManager::class);
        $adapter = $this->mock(S3Filesystem::class);

        $manager->expects('adapter')->with(Backup::ADAPTER_AWS_S3)->andReturn($adapter);
        $adapter->expects('getBucket')->andReturn('foobar');
        $adapter->expects('getClient->deleteObject')->with([
            'Bucket' => 'foobar',
            'Key' => sprintf('%s/%s.tar.gz', $server->uuid, $backup->uuid),
        ]);

        $this->delete('/api/application/backups/' . $backup->id)->assertNoContent();

        $this->assertSoftDeleted($backup);
    }

    public function testReadOnlyKeyCannotDeleteABackup(): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_servers' => AdminAcl::READ]);

        $server = $this->createServerModel();
        $backup = Backup::factory()->create(['server_id' => $server->id]);

        $this->assertAccessDeniedJson($this->delete('/api/application/backups/' . $backup->id));
    }

    public function testNonAdministratorIsRefused(): void
    {
        $this->getApiUser()->update(['root_admin' => false]);

        $this->getJson('/api/application/backups')->assertStatus(Response::HTTP_FORBIDDEN);
    }
}
