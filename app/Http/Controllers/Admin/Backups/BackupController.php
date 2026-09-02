<?php

namespace Pterodactyl\Http\Controllers\Admin\Backups;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Pterodactyl\Http\Controllers\Controller;

class BackupController extends Controller
{
    /**
     * Renders every backup the panel knows about, live and orphaned, merged into one
     * newest-first list. The "server" query string parameter scopes the list to that
     * server's live backups (an orphan has no server left to belong to), and the
     * "orphaned" parameter scopes it to orphans only; with neither set both halves are
     * merged together.
     */
    public function index(Request $request): View
    {
        $serverId = $request->filled('server') ? (int) $request->query('server') : null;
        $orphanedOnly = $request->boolean('orphaned');

        if ($orphanedOnly) {
            $query = $this->orphanedBackupsQuery()->orderByDesc('orphaned_backups.backup_created_at');
        } elseif ($serverId) {
            $query = $this->liveBackupsQuery($serverId)->orderByDesc('backups.created_at');
        } else {
            // A union can only be ordered by a bare column alias, since MySQL rejects a
            // qualified name there. The two single-table branches above qualify theirs
            // instead, so neither depends on how a bare "created_at" resolves against
            // the servers and nodes rows they join to, both of which carry a column of
            // that name themselves.
            $query = $this->liveBackupsQuery()
                ->unionAll($this->orphanedBackupsQuery())
                ->orderByDesc('created_at');
        }

        $backups = $query->paginate(25)->withQueryString();

        return view('admin.backups.index', [
            'backups' => $backups,
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'selectedServer' => $serverId,
            'orphanedOnly' => $orphanedOnly,
        ]);
    }

    /**
     * A normalized projection over the live backups table, optionally scoped to a
     * single server. This has to be built on the query builder rather than
     * Backup::query(): an Eloquent builder hydrates every row as a Backup model, which
     * is wrong once it is merged with the orphaned half below. SoftDeletes is an
     * Eloquent concern the query builder does not apply on its own, so the deleted_at
     * check here is what actually keeps removed backups out of the list.
     */
    private function liveBackupsQuery(?int $serverId = null): Builder
    {
        return DB::table('backups')
            ->join('servers', 'servers.id', '=', 'backups.server_id')
            ->leftJoin('nodes', 'nodes.id', '=', 'servers.node_id')
            ->whereNull('backups.deleted_at')
            ->when($serverId, fn (Builder $query) => $query->where('backups.server_id', $serverId))
            ->select([
                DB::raw("'live' as source"),
                'backups.id as id',
                'backups.uuid as backup_uuid',
                'servers.uuid as server_uuid',
                'servers.name as server_name',
                'servers.id as server_id',
                'servers.uuidShort as server_short_uuid',
                'servers.node_id as node_id',
                'nodes.name as node_name',
                'backups.disk as disk',
                'backups.name as name',
                'backups.bytes as bytes',
                'backups.created_at as created_at',
                DB::raw('NULL as orphaned_at'),
                'backups.is_successful as is_successful',
                'backups.is_locked as is_locked',
                'backups.completed_at as completed_at',
            ]);
    }

    /**
     * The same projection over the orphaned_backups table. The columns only the live
     * half can supply - the server's numeric id and short uuid, lock state, completion
     * time - come back null here. is_successful comes back true unconditionally rather
     * than null: OrphanBackupsListener only ever copies a successful, completed backup
     * into this table, so every row in it is successful by construction. The node join
     * is required here too, and not only on the live half, because
     * orphaned_backups.node_id is nullable once its node is itself deleted.
     */
    private function orphanedBackupsQuery(): Builder
    {
        return DB::table('orphaned_backups')
            ->leftJoin('nodes', 'nodes.id', '=', 'orphaned_backups.node_id')
            ->select([
                DB::raw("'orphaned' as source"),
                'orphaned_backups.id as id',
                'orphaned_backups.backup_uuid as backup_uuid',
                'orphaned_backups.server_uuid as server_uuid',
                'orphaned_backups.server_name as server_name',
                DB::raw('NULL as server_id'),
                DB::raw('NULL as server_short_uuid'),
                'orphaned_backups.node_id as node_id',
                'nodes.name as node_name',
                'orphaned_backups.disk as disk',
                'orphaned_backups.name as name',
                'orphaned_backups.bytes as bytes',
                'orphaned_backups.backup_created_at as created_at',
                'orphaned_backups.orphaned_at as orphaned_at',
                DB::raw('1 as is_successful'),
                DB::raw('NULL as is_locked'),
                DB::raw('NULL as completed_at'),
            ]);
    }
}
