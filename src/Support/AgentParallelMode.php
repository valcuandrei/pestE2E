<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Pest\Plugins\Parallel;

/**
 * Coordinates agent JSON output across Pest parallel processes.
 *
 * @internal
 */
final class AgentParallelMode
{
    private const GLOBAL_KEY = 'agent_output';

    /**
     * @param  array<int, mixed>  $arguments
     */
    public static function isCoordinator(array $arguments): bool
    {
        if (ParallelWorker::isParallel() || self::isParatestWorker()) {
            return false;
        }

        foreach ($arguments as $argument) {
            if ($argument === '--parallel' || $argument === '-p') {
                return true;
            }
        }

        return false;
    }

    public static function isParatestWorker(): bool
    {
        $value = $_SERVER['PARATEST'] ?? $_ENV['PARATEST'] ?? getenv('PARATEST');

        if (in_array($value, [false, null, ''], true)) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return is_numeric($value) && (int) $value === 1;
    }

    public static function activateCoordinator(): void
    {
        AgentOutputAggregator::prepareRun();

        if (class_exists(Parallel::class)) {
            Parallel::setGlobal(self::GLOBAL_KEY, '1');
        }
    }

    public static function enabledInWorker(): bool
    {
        if (! AgentOutputAggregator::hasActiveRun()) {
            return false;
        }

        if (ParallelWorker::isParallel() || self::isParatestWorker()) {
            return true;
        }

        if (class_exists(Parallel::class)) {
            return AgentOutput::isTruthy(Parallel::getGlobal(self::GLOBAL_KEY));
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function forwardableEnvironmentVariables(): array
    {
        $variables = [];

        foreach ([
            'PEST_E2E_AGENT_OUTPUT',
            'PAO_FORCE',
            'PAO_DISABLE',
            'PEST_E2E_AGENT_OUTPUT_DISABLE',
            'PEST_E2E_BROWSE',
            'PEST_E2E_DEBUG',
            'PEST_E2E_PACKAGE_MANAGER',
        ] as $key) {
            $value = getenv($key);

            if ($value !== false && $value !== '') {
                $variables[$key] = (string) $value;
            }
        }

        return $variables;
    }
}
