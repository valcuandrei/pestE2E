<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;

/**
 * Composition root for the public API.
 */
final class E2E
{
    public function __construct(
        private readonly TargetRegistry $registry,
        private readonly RunIdGeneratorContract $runIdGenerator,
        private readonly AuthTicketIssuerContract $authTicketIssuer,
        private readonly E2ERunner $runner,
    ) {}

    /**
     * Get the auth ticket issuer.
     */
    public function authTicketIssuer(): AuthTicketIssuerContract
    {
        return $this->authTicketIssuer;
    }

    /**
     * Generate a run ID.
     */
    public function generateRunId(): string
    {
        return $this->runIdGenerator->generate();
    }

    /**
     * Get the registry.
     */
    public function registry(): TargetRegistry
    {
        return $this->registry;
    }

    /**
     * Get the runner.
     */
    public function runner(): E2ERunner
    {
        return $this->runner;
    }
}
