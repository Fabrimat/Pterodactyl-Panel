<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Backups;

use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Spatie\QueryBuilder\QueryBuilder;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Transformers\Api\Application\BackupTransformer;
use Pterodactyl\Http\Requests\Api\Application\Backups\GetBackupRequest;
use Pterodactyl\Http\Requests\Api\Application\Backups\GetBackupsRequest;
use Pterodactyl\Http\Requests\Api\Application\Backups\BackupWriteRequest;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;

class BackupController extends ApplicationApiController
{
    /**
     * BackupController constructor.
     */
    public function __construct(private DeleteBackupService $deleteBackupService)
    {
        parent::__construct();
    }

    /**
     * Return all the backups that currently exist on the Panel.
     */
    public function index(GetBackupsRequest $request): array
    {
        $backups = QueryBuilder::for(Backup::query())
            ->allowedFilters(['uuid', 'server_id', 'disk', 'is_successful', 'is_locked'])
            ->allowedSorts(['id', 'uuid', 'created_at', 'bytes'])
            ->paginate($request->query('per_page') ?? 50);

        return $this->fractal->collection($backups)
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Show a single backup transformed for the application API.
     */
    public function view(GetBackupRequest $request, Backup $backup): array
    {
        return $this->fractal->item($backup)
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Deletes a backup and its underlying stored data. A locked backup is refused here
     * exactly like it is everywhere else - unlock it through the client API first.
     *
     * @throws \Throwable
     */
    public function delete(BackupWriteRequest $request, Backup $backup): Response
    {
        $this->deleteBackupService->handle($backup);

        return $this->returnNoContent();
    }
}
