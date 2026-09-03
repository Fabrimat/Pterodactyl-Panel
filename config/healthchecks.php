<?php

return [
    // The base URL of the healthchecks.io instance (or compatible self-hosted server) to
    // ping when a schedule finishes. Leave this empty to disable the feature entirely, even
    // if individual schedules have a check UUID configured.
    'url' => env('HEALTHCHECKS_URL', 'https://hc-ping.com'),
];
