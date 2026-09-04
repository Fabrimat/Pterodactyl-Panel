<?php

namespace Pterodactyl\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Events\Server\Deleting;
use Pterodactyl\Listeners\OrphanBackupsListener;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Repositories\Wings\BorgDaemonBackupRepository;

class BorgBackupServiceProvider extends ServiceProvider
{
    /**
     * Route every existing DaemonBackupRepository injection through the borg-aware
     * subclass, so that adding borg support does not require touching the wings and
     * s3 request logic, or anything that depends on it, directly.
     */
    public function register(): void
    {
        $this->app->bind(DaemonBackupRepository::class, BorgDaemonBackupRepository::class);
    }

    /**
     * Registered here despite covering every backup adapter, not just Borg, since this
     * is the fork's own provider and the orphaned-backups feature has no adapter of its
     * own to be registered from instead. Server::deleting() already dispatches this
     * event from upstream's ServerObserver, inside ServerDeletionService's transaction
     * and before the cascading foreign key removes the server's backup rows, so this is
     * the last point a backup that is about to be cascaded away can still be read.
     */
    public function boot(): void
    {
        Event::listen(Deleting::class, OrphanBackupsListener::class);
    }
}
