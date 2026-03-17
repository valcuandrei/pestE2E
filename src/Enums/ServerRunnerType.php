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

    /**
     * Use the PHP built-in server.
     * php -S 127.0.0.1:8000 -t public <package>/resources/server-router.php.
     */
    case PHP_BUILTIN = 'php_builtin';
}
