<?php

namespace Pterodactyl\Services\Nodes;

use Pterodactyl\Models\Node;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Repositories\Wings\DaemonConfigurationRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Reads the optional "features" list a node's Wings advertises off /api/system, and
 * gates fork-only backup operations (borg, orphaned backup delete) on it. A daemon
 * that predates this key sends nothing, and is therefore assumed to support nothing:
 * there is no way to distinguish "old daemon" from "daemon that genuinely has no
 * features" from the response alone, and both must be refused the same way.
 *
 * Deliberately uncached: a backup operation is a handful of requests per server per
 * day, and the request this check guards would incur the exact same daemon round trip
 * a moment later anyway. A cache would only buy staleness - most sharply right after an
 * operator upgrades a node and expects the very next request to succeed.
 */
class NodeFeatureService
{
    public const FEATURE_BORG = 'borg';
    public const FEATURE_ORPHANED_BACKUP_DELETE = 'orphaned-backup-delete';

    public function __construct(private DaemonConfigurationRepository $repository)
    {
    }

    /**
     * Returns the features a node's Wings advertises. Falls back to an empty array
     * when the key is absent or is not itself an array, so an upstream daemon - which
     * sends neither - is always read as supporting nothing.
     *
     * @throws DaemonConnectionException
     */
    public function features(Node $node): array
    {
        $information = $this->repository->setNode($node)->getSystemInformation();

        $features = $information['features'] ?? null;

        return is_array($features) ? $features : [];
    }

    /**
     * Confirms a node's Wings advertises the given feature before a fork-only backup
     * operation is sent to it. A DaemonConnectionException is left to propagate
     * untouched: an unreachable daemon is not evidence of an old one, and the request
     * this check guards would have failed on the exact same socket a moment later. Only
     * a daemon that actually answered, but did not list the feature, results in a
     * DisplayException here.
     *
     * @throws DaemonConnectionException
     * @throws DisplayException
     */
    public function assertSupports(Node $node, string $feature): void
    {
        if (in_array($feature, $this->features($node), true)) {
            return;
        }

        throw new DisplayException(
            "Node \"{$node->name}\" does not advertise support for the \"{$feature}\" feature. " .
            'Upgrade Wings on that node, or change the default backup adapter for this Panel.'
        );
    }
}
