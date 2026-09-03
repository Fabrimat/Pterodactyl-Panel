<?php

namespace Pterodactyl\Tests\Integration\Services\Backups;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

class BackupScheduleForeignKeyTest extends IntegrationTestCase
{
    /**
     * Deleting a server that owns both a schedule and a backup stamped with that
     * schedule must not raise a foreign key error. Both rows are removed by their
     * own server_id cascade, so this must succeed no matter which one the database
     * processes first.
     */
    public function testDeletingAServerWithAScheduledBackupDoesNotError(): void
    {
        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'schedule_id' => $schedule->id,
        ]);

        $server->delete();

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    /**
     * Deleting the schedule alone must leave the backup it stamped in place with a
     * null schedule_id. The foreign key is "on delete set null", not cascade,
     * because removing a schedule is not a reason to lose a server's backup.
     */
    public function testDeletingAScheduleAloneLeavesTheBackupWithANullScheduleId(): void
    {
        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'schedule_id' => $schedule->id,
        ]);

        $schedule->delete();

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
        $this->assertDatabaseHas('backups', ['id' => $backup->id, 'schedule_id' => null]);
    }
}
