<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Enums;

/**
 * The type of server to run.
 *
 * @see ServerRunner::run()
 *
 * @internal
 */
enum ServerRunnerType: string
{
    /**
     * Use the artisan server.
     */
    case ARTISAN = 'artisan';
}
