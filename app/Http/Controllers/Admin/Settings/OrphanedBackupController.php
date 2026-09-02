<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\View\View;
use Illuminate\Http\Response;
use Pterodactyl\Models\OrphanedBackup;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Backups\DeleteOrphanedBackupService;

class OrphanedBackupController extends Controller
{
    public function __construct(private DeleteOrphanedBackupService $deleteOrphanedBackupService)
    {
    }

    /**
     * Renders the list of backups whose server has since been deleted, newest orphan
     * first.
     */
    public function index(): View
    {
        $backups = OrphanedBackup::query()
            ->with('node')
            ->orderByDesc('orphaned_at')
            ->paginate(25);

        return view('admin.settings.backups-orphaned', ['backups' => $backups]);
    }

    /**
     * Removes the stored data behind an orphaned backup and then its Panel row.
     *
     * @throws \Throwable
     */
    public function delete(OrphanedBackup $orphaned_backup): Response
    {
        $this->deleteOrphanedBackupService->handle($orphaned_backup);

        return response('', 204);
    }

    /**
     * Removes only the Panel row for an orphaned backup, leaving whatever data still
     * exists on the node, in S3 or in a Borg repository untouched. This is the escape
     * hatch for a row the Panel can no longer act on - most notably one whose node has
     * itself been deleted - and not the normal way to get rid of one.
     */
    public function forget(OrphanedBackup $orphaned_backup): Response
    {
        $orphaned_backup->delete();

        return response('', 204);
    }
}
