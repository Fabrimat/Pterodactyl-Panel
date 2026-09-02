<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Admin\Backups;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\OrphanedBackup;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class BackupListTest extends HttpTestCase
{
    // IntegrationTestCase sets $connectionsToTransact but does not use this trait, so
    // nothing a test built on it writes is rolled back. Every backup and orphaned
    // backup row created below would otherwise survive into the next test in this class.
    use DatabaseTransactions;

    /**
     * An admin browsing this page is a browser, not an API client. The JSON Accept
     * header IntegrationTestCase sends by default would turn any validation or routing
     * failure into a 422 document instead of the page or redirect these tests assert
     * against.
     */
    protected $defaultHeaders = ['Accept' => 'text/html'];

    public function testIndexListsBothLiveAndOrphanedBackups(): void
    {
        $server = $this->createServerModel();
        $live = Backup::factory()->create(['server_id' => $server->id]);
        $orphan = OrphanedBackup::query()->create($this->payload());

        $this->actingAsAdmin()
            ->get(route('admin.backups'))
            ->assertOk()
            ->assertSee($live->uuid)
            ->assertSee($orphan->backup_uuid);
    }

    public function testServerFilterShowsOnlyThatServersLiveBackups(): void
    {
        $serverA = $this->createServerModel();
        $serverB = $this->createServerModel();
        $backupA = Backup::factory()->create(['server_id' => $serverA->id]);
        $backupB = Backup::factory()->create(['server_id' => $serverB->id]);
        $orphan = OrphanedBackup::query()->create($this->payload());

        $this->actingAsAdmin()
            ->get(route('admin.backups', ['server' => $serverA->id]))
            ->assertOk()
            ->assertSee($backupA->uuid)
            ->assertDontSee($backupB->uuid)
            ->assertDontSee($orphan->backup_uuid);
    }

    public function testOrphanedFilterShowsOnlyOrphans(): void
    {
        $server = $this->createServerModel();
        $live = Backup::factory()->create(['server_id' => $server->id]);
        $orphan = OrphanedBackup::query()->create($this->payload());

        $this->actingAsAdmin()
            ->get(route('admin.backups', ['orphaned' => 1]))
            ->assertOk()
            ->assertSee($orphan->backup_uuid)
            ->assertDontSee($live->uuid);
    }

    public function testSoftDeletedBackupIsNeverListed(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create(['server_id' => $server->id]);
        $backup->delete();

        $this->actingAsAdmin()
            ->get(route('admin.backups'))
            ->assertOk()
            ->assertDontSee($backup->uuid);
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
            ->get(route('admin.backups'))
            ->assertOk()
            ->assertSee($backup->backup_uuid)
            ->assertSee('stored data cannot be removed from here');
    }

    /**
     * The paginator has to carry the current filter into the links it renders for the
     * other pages, or navigating to page 2 silently drops back to the unfiltered list.
     */
    public function testServerFilterSurvivesPagingToPageTwo(): void
    {
        $server = $this->createServerModel();

        for ($i = 1; $i <= 26; ++$i) {
            Backup::factory()->create([
                'server_id' => $server->id,
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAsAdmin()
            ->get(route('admin.backups', ['server' => $server->id]))
            ->assertOk()
            ->assertSee("server={$server->id}&amp;page=2", false);
    }

    /**
     * Same guarantee as above, for the orphaned-only filter.
     */
    public function testOrphanedFilterSurvivesPagingToPageTwo(): void
    {
        for ($i = 1; $i <= 26; ++$i) {
            OrphanedBackup::query()->create($this->payload([
                'backup_created_at' => now()->subMinutes($i),
            ]));
        }

        $this->actingAsAdmin()
            ->get(route('admin.backups', ['orphaned' => 1]))
            ->assertOk()
            ->assertSee('orphaned=1&amp;page=2', false);
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
            'server_name' => 'orphaned-backup-list-test-server',
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
