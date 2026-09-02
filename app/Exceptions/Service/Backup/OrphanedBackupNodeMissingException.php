<?php

namespace Pterodactyl\Exceptions\Service\Backup;

use Pterodactyl\Exceptions\DisplayException;

class OrphanedBackupNodeMissingException extends DisplayException
{
    /**
     * OrphanedBackupNodeMissingException constructor.
     */
    public function __construct()
    {
        parent::__construct('The node this backup was stored on no longer exists, so its stored data cannot be deleted from here. Use "Forget" instead to remove only this record.');
    }
}
