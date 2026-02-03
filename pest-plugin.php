<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\PublicApi\E2E;
use ValcuAndrei\PestE2E\PublicApi\E2EProjectHandle;

if (! function_exists('e2e')) {
    /**
     * @return E2E|E2EProjectHandle
     */
    function e2e(?string $project = null)
    {
        $api = E2E::instance();

        return $project === null
            ? $api
            : $api->projectHandle($project);
    }
}
