<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of a backup's stored data that survived the deletion of the server it
 * belonged to. Server deletion cascades every row out of the backups table along
 * with the server itself, which would otherwise leave the Panel with no way to
 * even know a Borg repository, S3 object or node-side archive still exists, let
 * alone remove it. This table is that surviving record, populated once, at the
 * moment the server is deleted, and never touched by anything else afterwards.
 *
 * @property int $id
 * @property string $backup_uuid
 * @property string $server_uuid
 * @property string $server_name
 * @property int|null $node_id
 * @property string $disk
 * @property string $name
 * @property int $bytes
 * @property string|null $borg_repository
 * @property \Illuminate\Support\Carbon $backup_created_at
 * @property \Illuminate\Support\Carbon $orphaned_at
 * @property \Pterodactyl\Models\Node|null $node
 */
class OrphanedBackup extends Model
{
    protected $table = 'orphaned_backups';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'node_id' => 'integer',
        'bytes' => 'integer',
        'backup_created_at' => 'datetime',
        'orphaned_at' => 'datetime',
    ];

    /**
     * The node this backup's stored data lives on, if it still exists. A null value
     * here means the node was deleted after the backup was orphaned, which is the
     * one case a borg or wings row can no longer be deleted through the Panel at all.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
