<?php

namespace Pterodactyl\Services\Backups;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\OrphanedBackup;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Repositories\Wings\DaemonOrphanedBackupRepository;
use Pterodactyl\Exceptions\Service\Backup\OrphanedBackupNodeMissingException;

class DeleteOrphanedBackupService
{
    public function __construct(
        private ConnectionInterface $connection,
        private BackupManager $manager,
        private BorgConfigurationService $borgConfigurationService,
        private DaemonOrphanedBackupRepository $daemonOrphanedBackupRepository,
    ) {
    }

    /**
     * Deletes an orphaned backup's stored data and then its Panel row. This mirrors
     * DeleteBackupService, one branch per adapter, but every branch here works off the
     * server UUID and node this row kept around for exactly this purpose, since the
     * Server and Backup models that the regular delete path relies on are both gone.
     *
     * @throws \Throwable
     * @throws OrphanedBackupNodeMissingException
     */
    public function handle(OrphanedBackup $backup): void
    {
        if ($backup->disk === Backup::ADAPTER_AWS_S3) {
            $this->deleteFromS3($backup);

            return;
        }

        // Neither the wings nor the borg adapter can be reached once the node that held
        // the backup is itself gone - there is nowhere left to send the delete request
        // to. The admin view never renders a Delete action for a row in this state, but
        // a DisplayException is what turns this into a real message instead of an
        // opaque 500 for anything that reaches this guard anyway.
        if (is_null($backup->node_id)) {
            throw new OrphanedBackupNodeMissingException();
        }

        $node = Node::query()->findOrFail($backup->node_id);
        $borg = $backup->disk === Backup::ADAPTER_BORG
            ? $this->borgConfigurationService->handle($backup->server_uuid, $backup->backup_uuid, $backup->borg_repository)
            : null;

        // No tolerance for a 404 here, unlike the regular delete path: Wings registers
        // a node-scoped route for this, but a borg body can only ever get back a 400
        // or a 204 from it, never a 404, so a 404 is only reachable at all for the
        // plain wings adapter, whose delete path really does look for a file on disk.
        // This code does not yet distinguish that case from any other failure, so even
        // a genuine "already gone" 404 for a wings row still keeps the row and surfaces
        // the error rather than being treated as done. Any failure - 404 included -
        // keeps the row and surfaces the error instead: a row that outlives its data is
        // a tidiness problem Forget can resolve by hand, while a dropped row whose data
        // survives is unrecoverable.
        $this->connection->transaction(function () use ($backup, $node, $borg) {
            $this->daemonOrphanedBackupRepository->setNode($node)->delete($backup, $borg);
            $backup->delete();
        });
    }

    /**
     * Deletes an orphaned backup stored in S3. The key is built from exactly the two
     * UUIDs DeleteBackupService::deleteFromS3() uses, both of which this row keeps
     * around specifically so this branch never needs a live Server or Backup model.
     *
     * @throws \Throwable
     */
    protected function deleteFromS3(OrphanedBackup $backup): void
    {
        $this->connection->transaction(function () use ($backup) {
            $backup->delete();

            /** @var \Pterodactyl\Extensions\Filesystem\S3Filesystem $adapter */
            $adapter = $this->manager->adapter(Backup::ADAPTER_AWS_S3);

            // @phpstan-ignore-next-line method.notFound
            $adapter->getClient()->deleteObject([
                'Bucket' => $adapter->getBucket(),
                'Key' => sprintf('%s/%s.tar.gz', $backup->server_uuid, $backup->backup_uuid),
            ]);
        });
    }
}
