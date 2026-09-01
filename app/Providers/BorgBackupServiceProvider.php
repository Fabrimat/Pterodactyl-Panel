<?php

namespace Pterodactyl\Providers;

use Illuminate\Support\ServiceProvider;
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
}
