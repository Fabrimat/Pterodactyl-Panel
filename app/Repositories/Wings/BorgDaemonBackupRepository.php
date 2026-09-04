<?php

namespace Pterodactyl\Repositories\Wings;

use Pterodactyl\Models\Node;
use Webmozart\Assert\Assert;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Nodes\NodeFeatureService;
use Pterodactyl\Services\Backups\BorgConfigurationService;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Extends the default daemon backup requests with the configuration Wings needs to run
 * a borg backup, restore or delete. Every method here checks whether borg is actually
 * the adapter in play for the request first, and defers to the parent implementation
 * unchanged when it is not, so the wings and s3 request bodies never drift from upstream.
 */
class BorgDaemonBackupRepository extends DaemonBackupRepository
{
    /**
     * Tells the remote Daemon to begin generating a backup for the server.
     *
     * @throws DaemonConnectionException
     */
    public function backup(Backup $backup): ResponseInterface
    {
        $adapter = $this->adapter ?? config('backups.default');
        if ($adapter !== Backup::ADAPTER_BORG) {
            return parent::backup($backup);
        }

        Assert::isInstanceOf($this->server, Server::class);

        $configuration = $this->configuration();

        // Recorded before the request is sent, and inside the same transaction that
        // created this backup row, so a mode change made afterwards can never leave a
        // pre-existing backup pointed at the wrong repository. NULL under the
        // incremental mode: repository() falls back to the server UUID on its own, so
        // only the snapshot mode's per-backup suffix is ever actually written here.
        $repositorySuffix = $configuration->newRepositorySuffix($this->server->uuid, $backup->uuid);
        $backup->update(['borg_repository' => $repositorySuffix]);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/backup', $this->server->uuid),
                [
                    'json' => [
                        'adapter' => $adapter,
                        'uuid' => $backup->uuid,
                        'ignore' => implode("\n", $backup->ignored_files),
                        'borg' => $configuration->handle($this->server->uuid, $backup->uuid, $repositorySuffix),
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Sends a request to Wings to begin restoring a backup for a server.
     *
     * @throws DaemonConnectionException
     * @throws DisplayException
     */
    public function restore(Backup $backup, ?string $url = null, bool $truncate = false): ResponseInterface
    {
        if ($backup->disk !== Backup::ADAPTER_BORG) {
            return parent::restore($backup, $url, $truncate);
        }

        Assert::isInstanceOf($this->server, Server::class);
        Assert::isInstanceOf($this->node, Node::class);

        $this->nodeFeatures()->assertSupports($this->node, NodeFeatureService::FEATURE_BORG);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/backup/%s/restore', $this->server->uuid, $backup->uuid),
                [
                    'json' => [
                        'adapter' => $backup->disk,
                        'truncate_directory' => $truncate,
                        'download_url' => $url ?? '',
                        'borg' => $this->configuration()->handle($this->server->uuid, $backup->uuid, $backup->borg_repository),
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Deletes a backup from the daemon. Unlike the parent implementation this sends a
     * body along with the request, since Wings needs the repository and passphrase in
     * order to remove the archive.
     *
     * @throws DaemonConnectionException
     * @throws DisplayException
     */
    public function delete(Backup $backup): ResponseInterface
    {
        if ($backup->disk !== Backup::ADAPTER_BORG) {
            return parent::delete($backup);
        }

        Assert::isInstanceOf($this->server, Server::class);
        Assert::isInstanceOf($this->node, Node::class);

        $this->nodeFeatures()->assertSupports($this->node, NodeFeatureService::FEATURE_BORG);

        try {
            return $this->getHttpClient()->delete(
                sprintf('/api/servers/%s/backup/%s', $this->server->uuid, $backup->uuid),
                [
                    'json' => [
                        'borg' => $this->configuration()->handle($this->server->uuid, $backup->uuid, $backup->borg_repository),
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Resolves the service that builds the borg configuration payload out of the
     * container rather than newing it up directly.
     */
    protected function configuration(): BorgConfigurationService
    {
        return $this->app->make(BorgConfigurationService::class);
    }

    /**
     * Resolves the service that checks whether a node's Wings advertises the feature a
     * borg request requires, out of the container rather than newing it up directly.
     */
    protected function nodeFeatures(): NodeFeatureService
    {
        return $this->app->make(NodeFeatureService::class);
    }
}
