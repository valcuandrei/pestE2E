<?php

namespace ValcuAndrei\PestE2E\Contracts;

use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;

interface JsWorkerContract
{
    /**
     * Run the JS worker.
     */
    public function run(ProcessPlanDTO $plan): ProcessResultDTO;
}
