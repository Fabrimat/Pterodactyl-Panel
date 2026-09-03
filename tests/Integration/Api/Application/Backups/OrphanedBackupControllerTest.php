<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Backups;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Node;
use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\OrphanedBackup;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Extensions\Filesystem\S3Filesystem;
use Pterodactyl\Repositories\Wings\DaemonOrphanedBackupRepository;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class OrphanedBackupControllerTest extends ApplicationApiIntegrationTestCase
{
    public function testOrphanedBackupsAreListedWithTheServerName(): void
    {
        $wingsOrphan = OrphanedBackup::query()->create($this->payload());
        $s3Orphan = OrphanedBackup::query()->create($this->payload([
            'disk' => Backup::ADAPTER_AWS_S3,
            'node_id' => null,
        ]));

        $response = $this->getJson('/api/application/orphaned-backups');
        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->pluck('attributes.server_name')->all();
        $this->assertEqualsCanonicalizing([$wingsOrphan->server_name, $s3Orphan->server_name], $names);
    }

    public function testOrphanedBackupsCanBeFilteredByBackupUuidAndSorted(): void
    {
        $matching = OrphanedBackup::query()->create($this->payload(['bytes' => 1024]));
        OrphanedBackup::query()->create($this->payload(['bytes' => 2048]));

        $response = $this->getJson('/api/application/orphaned-backups?filter[backup_uuid]=' . $matching->backup_uuid);
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.attributes.id', $matching->id);

        $response = $this->getJson('/api/application/orphaned-backups?sort=-bytes');
        $response->assertOk();
        $response->assertJsonPath('data.0.attributes.bytes', 2048);
        $response->assertJsonPath('data.1.attributes.bytes', 1024);
    }

    public function testIncludeNodeEmbedsTheNode(): void
    {
        $node = Node::factory()->for(Location::factory())->create();
        $orphan = OrphanedBackup::query()->create($this->payload(['node_id' => $node->id]));

        $response = $this->getJson('/api/application/orphaned-backups/' . $orphan->id . '?include=node');
        $response->assertOk();
        $response->assertJsonPath('attributes.relationships.node.attributes.uuid', $node->uuid);
    }

    public function testViewSingleOrphanedBackup(): void
    {
        $orphan = OrphanedBackup::query()->create($this->payload());

        $this->getJson('/api/application/orphaned-backups/' . $orphan->id)
            ->assertOk()
            ->assertJsonPath('attributes.backup_uuid', $orphan->backup_uuid);
    }

    public function testForgetRemovesOnlyThePanelRowWithoutContactingAnyNode(): void
    {
        $orphan = OrphanedBackup::query()->create($this->payload());

        $daemon = $this->mock(DaemonOrphanedBackupRepository::class);
        $daemon->shouldNotReceive('setNode');

        $this->postJson('/api/application/orphaned-backups/' . $orphan->id . '/forget')
            ->assertNoContent();

        $this->assertDatabaseMissing('orphaned_backups', ['id' => $orphan->id]);
    }

    public function testDeleteGoesThroughTheDeleteOrphanedBackupService(): void
    {
        $orphan = OrphanedBackup::query()->create($this->payload([
            'disk' => Backup::ADAPTER_AWS_S3,
            'node_id' => null,
        ]));

        $manager = $this->mock(BackupManager::class);
        $adapter = $this->mock(S3Filesystem::class);

        $manager->expects('adapter')->with(Backup::ADAPTER_AWS_S3)->andReturn($adapter);
        $adapter->expects('getBucket')->andReturn('foobar');
        $adapter->expects('getClient->deleteObject')->with([
            'Bucket' => 'foobar',
            'Key' => sprintf('%s/%s.tar.gz', $orphan->server_uuid, $orphan->backup_uuid),
        ]);

        $this->delete('/api/application/orphaned-backups/' . $orphan->id)->assertNoContent();

        $this->assertDatabaseMissing('orphaned_backups', ['id' => $orphan->id]);
    }

    // These are two tests rather than one because Handler::render() rolls the connection
    // all the way back to the start of the transaction whenever it renders an exception
    // while one is open, which a wrapped integration test always has. The orphan row
    // created for the first refused request would already be gone by the time a second
    // request in the same test tried to reuse it - route model binding would 404 on it
    // instead of the request ever reaching the permission check being tested here.
    public function testReadOnlyKeyCannotDeleteAnOrphanedBackup(): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_servers' => AdminAcl::READ]);

        $orphan = OrphanedBackup::query()->create($this->payload());

        $this->assertAccessDeniedJson($this->delete('/api/application/orphaned-backups/' . $orphan->id));
    }

    public function testReadOnlyKeyCannotForgetAnOrphanedBackup(): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_servers' => AdminAcl::READ]);

        $orphan = OrphanedBackup::query()->create($this->payload());

        $this->assertAccessDeniedJson($this->postJson('/api/application/orphaned-backups/' . $orphan->id . '/forget'));
    }

    public function testNonAdministratorIsRefused(): void
    {
        $this->getApiUser()->update(['root_admin' => false]);

        $this->getJson('/api/application/orphaned-backups')->assertStatus(Response::HTTP_FORBIDDEN);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'backup_uuid' => Uuid::uuid4()->toString(),
            'server_uuid' => Uuid::uuid4()->toString(),
            'server_name' => 'orphaned-application-test-server',
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
