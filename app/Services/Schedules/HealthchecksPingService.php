<?php

namespace Pterodactyl\Services\Schedules;

use Pterodactyl\Models\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class HealthchecksPingService
{
    /**
     * Pings the healthchecks.io check configured on the schedule, if any. The bare check
     * URL signals success; passing '/fail' as the suffix signals a failed run.
     *
     * A ping failure of any kind (timeout, DNS, non-2xx response) must never affect the
     * schedule it belongs to, so every exception raised while making the request is caught
     * and only logged.
     */
    public function ping(Schedule $schedule, string $suffix = ''): void
    {
        $base = config('healthchecks.url');
        if (empty($schedule->healthchecks_uuid) || empty($base)) {
            return;
        }

        $url = rtrim($base, '/') . '/' . $schedule->healthchecks_uuid . $suffix;

        try {
            Http::connectTimeout(2)->timeout(5)->get($url);
        } catch (\Throwable $e) {
            Log::warning($e, ['schedule_id' => $schedule->id, 'healthchecks_uuid' => $schedule->healthchecks_uuid]);
        }
    }
}
