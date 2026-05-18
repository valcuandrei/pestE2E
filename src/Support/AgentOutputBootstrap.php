<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * @internal
 */
final class AgentOutputBootstrap
{
    public static function boot(): void
    {
        AgentOutputIntent::hydrateEnvironment();

        $rawArguments = $_SERVER['argv'] ?? null;

        if (! is_array($rawArguments)) {
            return;
        }

        /** @var array<int, mixed> $arguments */
        $arguments = array_values($rawArguments);

        CliOptions::fromArguments($arguments);

        if (! CliOptions::agentOutput()) {
            if (AgentOutputAggregator::hasActiveRun()) {
                AgentOutputAggregator::cleanup();
            }

            return;
        }

        AgentOutput::silenceTestRunnerOutput();

        if (AgentParallelMode::isCoordinator($arguments)) {
            AgentParallelMode::activateCoordinator();
        }
    }
}
