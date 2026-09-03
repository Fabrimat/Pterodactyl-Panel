<?php

namespace Pterodactyl\Transformers\Api\Application;

use League\Fractal\Resource\Item;
use Pterodactyl\Models\OrphanedBackup;
use League\Fractal\Resource\NullResource;
use Pterodactyl\Services\Acl\Api\AdminAcl;

class OrphanedBackupTransformer extends BaseTransformer
{
    /**
     * List of resources that can be included.
     */
    protected array $availableIncludes = ['node'];

    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return OrphanedBackup::RESOURCE_NAME;
    }

    /**
     * Return a generic transformed orphaned backup array.
     */
    public function transform(OrphanedBackup $backup): array
    {
        return [
            'id' => $backup->id,
            'backup_uuid' => $backup->backup_uuid,
            'server_uuid' => $backup->server_uuid,
            'server_name' => $backup->server_name,
            'node_id' => $backup->node_id,
            'disk' => $backup->disk,
            'name' => $backup->name,
            'bytes' => $backup->bytes,
            'borg_repository' => $backup->borg_repository,
            'backup_created_at' => $this->formatTimestamp($backup->backup_created_at),
            'orphaned_at' => $this->formatTimestamp($backup->orphaned_at),
        ];
    }

    /**
     * Return the node this backup's stored data lives on. This is a NullResource both
     * when the key cannot see node data and when the node itself has been deleted -
     * the one case a borg or wings row can no longer be acted on through the Panel.
     *
     * @throws \Pterodactyl\Exceptions\Transformer\InvalidTransformerLevelException
     */
    public function includeNode(OrphanedBackup $backup): Item|NullResource
    {
        if (!$this->authorize(AdminAcl::RESOURCE_NODES)) {
            return $this->null();
        }

        $backup->loadMissing('node');
        $node = $backup->getRelation('node');

        if (is_null($node)) {
            return $this->null();
        }

        return $this->item($node, $this->makeTransformer(NodeTransformer::class), 'node');
    }
}
