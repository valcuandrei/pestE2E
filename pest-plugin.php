<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\PublicApi\E2E;
use ValcuAndrei\PestE2E\PublicApi\E2ETargetHandle;

if (! function_exists('e2e')) {
    /**
     * @return E2E|E2ETargetHandle
     */
    function e2e(?string $target = null)
    {
        $api = E2E::instance();

        return $target === null
            ? $api
            : $api->targetHandle($target);
    }
}
