<?php

namespace Pterodactyl\Repositories\Wings;

use Pterodactyl\Models\OrphanedBackup;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Deletes an orphaned backup's stored data directly from the node that held it, without
 * a Server model to route the request through - the server it belonged to is gone by
 * the time this row can be acted on. setNode() is all this needs, since the node-scoped
 * delete route this hits identifies the backup by its own UUID alone.
 *
 * @method \Pterodactyl\Repositories\Wings\DaemonOrphanedBackupRepository setNode(\Pterodactyl\Models\Node $node)
 */
class DaemonOrphanedBackupRepository extends DaemonRepository
{
    /**
     * Deletes a backup from the daemon. A borg row carries its configuration object
     * along in the request body, exactly as the regular per-server delete does; a
     * wings row sends no body at all, since Wings already knows where its own local
     * archive lives from the backup UUID.
     *
     * @throws DaemonConnectionException
     */
    public function delete(OrphanedBackup $backup, ?array $borg = null): ResponseInterface
    {
        try {
            return $this->getHttpClient()->delete(
                sprintf('/api/backups/%s', $backup->backup_uuid),
                is_null($borg) ? [] : ['json' => ['borg' => $borg]]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }
}
