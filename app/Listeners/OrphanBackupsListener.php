<?php

namespace Pterodactyl\Listeners;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\OrphanedBackup;
use Pterodactyl\Events\Server\Deleting;

/**
 * Runs inside ServerDeletionService's transaction, before the server row - and every
 * backup row cascaded from it - is actually gone. A rollback of that transaction rolls
 * these inserts back with it, so a failed deletion never leaves an orphan record for a
 * server that is still very much present.
 */
class OrphanBackupsListener
{
    public function handle(Deleting $event): void
    {
        $server = $event->server;

        // Only a backup with data actually behind it is worth a row here. A failed or
        // still-running backup never finished writing anything a node, S3 or Borg
        // needs to be asked to remove, and a soft-deleted one already had its data
        // removed by DeleteBackupService - the default query scope from SoftDeletes
        // excludes those without anything further needed here.
        $backups = $server->backups()
            ->where('is_successful', true)
            ->whereNotNull('completed_at')
            ->get();

        foreach ($backups as $backup) {
            /* @var Backup $backup */
            OrphanedBackup::query()->create([
                'backup_uuid' => $backup->uuid,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'node_id' => $server->node_id,
                'disk' => $backup->disk,
                'name' => $backup->name,
                'bytes' => $backup->bytes,
                'borg_repository' => $backup->borg_repository,
                'backup_created_at' => $backup->created_at,
                'orphaned_at' => now(),
            ]);
        }
    }
}
