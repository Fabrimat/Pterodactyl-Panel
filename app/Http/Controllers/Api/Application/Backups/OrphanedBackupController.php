<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Backups;

use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;
use Pterodactyl\Models\OrphanedBackup;
use Pterodactyl\Services\Backups\DeleteOrphanedBackupService;
use Pterodactyl\Transformers\Api\Application\OrphanedBackupTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Http\Requests\Api\Application\Backups\GetOrphanedBackupRequest;
use Pterodactyl\Http\Requests\Api\Application\Backups\GetOrphanedBackupsRequest;
use Pterodactyl\Http\Requests\Api\Application\Backups\OrphanedBackupWriteRequest;

class OrphanedBackupController extends ApplicationApiController
{
    /**
     * OrphanedBackupController constructor.
     */
    public function __construct(private DeleteOrphanedBackupService $deleteOrphanedBackupService)
    {
        parent::__construct();
    }

    /**
     * Return all the orphaned backups the Panel still holds a record of.
     */
    public function index(GetOrphanedBackupsRequest $request): array
    {
        $backups = QueryBuilder::for(OrphanedBackup::query())
            ->allowedFilters(['backup_uuid', 'server_uuid', 'disk', 'node_id'])
            ->allowedSorts(['id', 'backup_created_at', 'bytes'])
            ->paginate($request->query('per_page') ?? 50);

        return $this->fractal->collection($backups)
            ->transformWith($this->getTransformer(OrphanedBackupTransformer::class))
            ->toArray();
    }

    /**
     * Show a single orphaned backup transformed for the application API.
     */
    public function view(GetOrphanedBackupRequest $request, OrphanedBackup $orphaned_backup): array
    {
        return $this->fractal->item($orphaned_backup)
            ->transformWith($this->getTransformer(OrphanedBackupTransformer::class))
            ->toArray();
    }

    /**
     * Removes the stored data behind an orphaned backup and then its Panel row.
     *
     * @throws \Throwable
     */
    public function delete(OrphanedBackupWriteRequest $request, OrphanedBackup $orphaned_backup): Response
    {
        $this->deleteOrphanedBackupService->handle($orphaned_backup);

        return $this->returnNoContent();
    }

    /**
     * Removes only the Panel row for an orphaned backup, leaving whatever data still
     * exists on the node, in S3 or in a Borg repository untouched. The escape hatch for
     * a row the Panel can no longer act on, not the normal way to get rid of one.
     */
    public function forget(OrphanedBackupWriteRequest $request, OrphanedBackup $orphaned_backup): Response
    {
        $orphaned_backup->delete();

        return $this->returnNoContent();
    }
}
