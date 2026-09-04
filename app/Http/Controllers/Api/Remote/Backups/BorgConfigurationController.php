<?php

namespace Pterodactyl\Http\Controllers\Api\Remote\Backups;

use Illuminate\Http\Request;
use Pterodactyl\Models\Backup;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;
use Pterodactyl\Services\Backups\BorgConfigurationService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class BorgConfigurationController extends Controller
{
    /**
     * BorgConfigurationController constructor.
     */
    public function __construct(private BorgConfigurationService $configurationService)
    {
    }

    /**
     * Returns the borg configuration for a backup so that Wings can serve a download
     * of it without ever having been handed the repository passphrase up front. The
     * push path (backup, restore, delete) already carries this object; this is the
     * pull path for the one request Wings makes off the back of a browser instead
     * of the Panel.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function __invoke(Request $request, string $backup): JsonResponse
    {
        // Get the node associated with the request.
        /** @var \Pterodactyl\Models\Node $node */
        $node = $request->attributes->get('node');

        $model = Backup::query()->where('uuid', $backup)->firstOrFail();

        // Check that the backup is "owned" by the node making the request. This avoids other nodes
        // from messing with backups that they don't own.
        $server = $model->server;
        if ($server->node_id !== $node->id) {
            throw new HttpForbiddenException('Requesting node does not have permission to access this server.');
        }

        if ($model->disk !== Backup::ADAPTER_BORG) {
            throw new BadRequestHttpException('This backup is not stored using the borg adapter.');
        }

        // A null borg_repository resolves to the plain per-server repository inside
        // handle() itself - see BorgConfigurationService::repository() - whether
        // because this backup predates that column or because it was written while
        // the mode was incremental.
        return new JsonResponse($this->configurationService->handle($server->uuid, $model->uuid, $model->borg_repository));
    }
}
