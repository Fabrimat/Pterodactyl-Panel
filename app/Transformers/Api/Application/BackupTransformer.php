<?php

namespace Pterodactyl\Transformers\Api\Application;

use Pterodactyl\Models\Backup;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\NullResource;
use Pterodactyl\Services\Acl\Api\AdminAcl;

class BackupTransformer extends BaseTransformer
{
    /**
     * List of resources that can be included.
     */
    protected array $availableIncludes = ['server'];

    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return Backup::RESOURCE_NAME;
    }

    /**
     * Return a generic transformed backup array.
     */
    public function transform(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'server_id' => $backup->server_id,
            'uuid' => $backup->uuid,
            'name' => $backup->name,
            'ignored_files' => $backup->ignored_files,
            'disk' => $backup->disk,
            'borg_repository' => $backup->borg_repository,
            'checksum' => $backup->checksum,
            'bytes' => $backup->bytes,
            'is_successful' => $backup->is_successful,
            'is_locked' => $backup->is_locked,
            'completed_at' => $backup->completed_at ? $this->formatTimestamp($backup->completed_at) : null,
            'created_at' => $this->formatTimestamp($backup->created_at),
            'updated_at' => $this->formatTimestamp($backup->updated_at),
        ];
    }

    /**
     * Return the server this backup belongs to.
     *
     * @throws \Pterodactyl\Exceptions\Transformer\InvalidTransformerLevelException
     */
    public function includeServer(Backup $backup): Item|NullResource
    {
        if (!$this->authorize(AdminAcl::RESOURCE_SERVERS)) {
            return $this->null();
        }

        $backup->loadMissing('server');

        return $this->item($backup->getRelation('server'), $this->makeTransformer(ServerTransformer::class), 'server');
    }
}
