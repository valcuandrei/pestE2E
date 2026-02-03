<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\ProjectRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;
use ValcuAndrei\PestE2E\Support\NullAuthTicketIssuer;
use ValcuAndrei\PestE2E\Support\RandomRunIdGenerator;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

/**
 * Composition root for the public API.
 */
final class E2E
{
    private static ?self $instance = null;

    private readonly ProjectRegistry $registry;

    private RunIdGeneratorContract $runIdGenerator;

    private AuthTicketIssuerContract $authTicketIssuer;

    private function __construct()
    {
        $this->registry = new ProjectRegistry;
        $this->runIdGenerator = new RandomRunIdGenerator;
        $this->authTicketIssuer = new NullAuthTicketIssuer;
    }

    /**
     * Get the instance of the E2E class.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * Reset the instance of the E2E class.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Use a run ID generator.
     */
    public function useRunIdGenerator(RunIdGeneratorContract $generator): void
    {
        $this->runIdGenerator = $generator;
    }

    /**
     * Use an auth ticket issuer.
     */
    public function useAuthTicketIssuer(AuthTicketIssuerContract $issuer): void
    {
        $this->authTicketIssuer = $issuer;
    }

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
    public function registry(): ProjectRegistry
    {
        return $this->registry;
    }

    /**
     * Get the runner.
     */
    public function runner(): E2ERunner
    {
        return new E2ERunner(
            registry: $this->registry,
            planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter),
            processRunner: new ProcessRunner,
            reportReader: new JsonReportReader,
            runIdGenerator: $this->runIdGenerator,
        );
    }
}
