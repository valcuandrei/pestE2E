<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

use ValcuAndrei\PestE2E\DTO\ResourceSampleDTO;

/**
 * Produces a snapshot of host resource pressure for the adaptive E2E
 * admission controller.
 *
 * Implementations must not throw on missing platform primitives; they should
 * return `null` for any metric they cannot read, so the controller can
 * fail-open on that metric rather than deadlock the test suite.
 */
interface ResourceSamplerContract
{
    public function sample(): ResourceSampleDTO;
}
