<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\ProjectRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;
use ValcuAndrei\PestE2E\Support\RandomRunIdGenerator;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

/**
 * Composition root for the public API.
 */
final class E2E
{
    private static ?self $instance = null;
    private ProjectRegistry $registry;
    private RunIdGeneratorContract $runIdGenerator;

    private function __construct()
    {
        $this->registry = new ProjectRegistry();
        $this->runIdGenerator = new RandomRunIdGenerator();
    }

    /**
     * Get the instance of the E2E class.
     * @return self
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
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
     * @param RunIdGeneratorContract $generator
     */
    public function useRunIdGenerator(RunIdGeneratorContract $generator): void
    {
        $this->runIdGenerator = $generator;
    }

    /**
     * Get the registry.
     * @return ProjectRegistry
     */
    public function registry(): ProjectRegistry
    {
        return $this->registry;
    }

    /**
     * Get the runner.
     * @return E2ERunner
     */
    public function runner(): E2ERunner
    {
        return new E2ERunner(
            registry: $this->registry,
            planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter()),
            processRunner: new ProcessRunner(),
            reportReader: new JsonReportReader(),
            runIdGenerator: $this->runIdGenerator,
        );
    }
}
