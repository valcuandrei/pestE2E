<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use Pest\TestSuite;
use RuntimeException;
use ValcuAndrei\PestE2E\DTO\AuthPayloadDTO;
use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;
use ValcuAndrei\PestE2E\Enums\AuthModeType;
use ValcuAndrei\PestE2E\Enums\ServerRunnerType;
use ValcuAndrei\PestE2E\Runners\ServerRunner;
use ValcuAndrei\PestE2E\Support\CliOptions;
use ValcuAndrei\PestE2E\Support\CurrentPhpunitTestContext;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;
use ValcuAndrei\PestE2E\Support\TimingProbe;

/**
 * Returned by e2e('frontend')
 */
final class E2ETargetHandle
{
    /** @var array<string,string|null> */
    private array $env = [];

    /** @var array<string,mixed> */
    private array $params = [];

    private ?ProcessOptionsDTO $options = null;

    private ?string $testFilter = null;

    public function __construct(
        private readonly string $target,
        private readonly CompositionRoot $root,
        private readonly E2EOutputFormatter $outputFormatter,
        private readonly E2EOutputStore $outputStore,
        private readonly CurrentPhpunitTestContext $testContext,
    ) {}

    /**
     * With environment variables.
     *
     * @param  array<string,string|null>  $env
     */
    public function withEnv(array $env): self
    {
        $clone = clone $this;
        $clone->env = array_replace($clone->env, $env);

        return $clone;
    }

    /**
     * With parameters.
     *
     * @param  array<string,mixed>  $params
     */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = array_replace($clone->params, $params);

        return $clone;
    }

    /**
     * With auth ticket.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withAuthTicket(AuthPayloadDTO $payload, array $meta = []): self
    {
        /** @var array<string, mixed> $mergedParams */
        $mergedParams = array_replace_recursive($this->params, [
            'auth' => array_merge(
                $payload->toArray(),
                $meta !== [] ? ['meta' => $this->normalizeMeta($meta)] : [],
            ),
        ]);

        $clone = clone $this;
        $clone->params = $mergedParams;

        return $clone;
    }

    /**
     * Issue an auth ticket for a user.
     *
     * @param  array<string, mixed>  $options
     */
    public function actingAs(mixed $user, array $options = []): self
    {
        [$guard, $mode, $meta] = $this->extractAuthOptions($options);

        $issuer = $this->root->authTicketIssuer();
        $ticket = $issuer->issueForUser($user, [
            'guard' => $guard,
            'meta' => $meta,
        ]);

        return $this->withAuthTicket(
            payload: new AuthPayloadDTO(
                ticket: $ticket,
                mode: $mode,
                guard: $guard,
            ),
            meta: $meta,
        );
    }

    /**
     * Alias for actingAs().
     *
     * @param  array<string, mixed>  $options
     */
    public function loginAs(mixed $user, array $options = []): self
    {
        return $this->actingAs($user, $options);
    }

    /**
     * Extract the auth options from the given options.
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *  0:string,
     *  1:AuthModeType,
     *  2:array<string, mixed>,
     * }
     */
    private function extractAuthOptions(array $options): array
    {
        $guard = 'web';
        if (array_key_exists('guard', $options)) {
            $guard = is_string($options['guard']) ? $options['guard'] : 'web';
        }

        $modeValue = $options['mode'] ?? AuthModeType::SESSION->value;
        if (! is_string($modeValue) && ! is_int($modeValue)) {
            $modeValue = AuthModeType::SESSION->value;
        }

        $mode = AuthModeType::tryFrom($modeValue) ?? AuthModeType::SESSION;

        if (array_key_exists('meta', $options) && is_array($options['meta'])) {
            return [$guard, $mode, $this->normalizeMeta($options['meta'])];
        }

        $meta = $options;
        unset($meta['guard'], $meta['mode']);

        return [$guard, $mode, $this->normalizeMeta($meta)];
    }

    /**
     * Normalize the meta data.
     *
     * @param  array<mixed, mixed>  $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $normalized = [];

        foreach ($meta as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * With options.
     */
    public function withOptions(ProcessOptionsDTO $options): self
    {
        $clone = clone $this;
        $clone->options = $options;

        return $clone;
    }

    /**
     * run() — run suite, fail on JS failures
     */
    public function run(): void
    {
        $runId = $this->root->generateRunId();
        $startedAt = microtime(true);
        $parentTestName = $this->currentTestName();
        TimingProbe::mark('php_test_start', [
            'target' => $this->target,
            'runId' => $runId,
            'browse' => CliOptions::$browse,
            'debug' => CliOptions::$debug,
            'testFilter' => $this->testFilter,
        ]);

        $report = null;
        $ok = false;
        $thrown = null;
        $extraLines = [];

        if (CliOptions::$debug) {
            fwrite(STDERR, "\n⚠ Debug mode active. Browser will remain open on failure.\n");
        }

        try {
            $serverType = ServerRunnerType::tryFrom(config()->string('pest-e2e.server.driver', ServerRunnerType::ARTISAN->value)) ?? ServerRunnerType::ARTISAN;

            [$report, $ok, $thrown] = ServerRunner::instance($serverType)->whenReady(
                /** @return array{0: JsonReportDTO, 1: bool, 2: ?RuntimeException} */
                function (string $baseUrl) use ($runId): array {
                    $report = $this->root->runner()->run(
                        targetName: $this->target,
                        env: array_merge($this->env, ['APP_URL' => $baseUrl]),
                        params: $this->params,
                        options: $this->options,
                        runId: $runId,
                        testFilter: $this->testFilter,
                    );

                    $ok = ! $report->hasFailures();
                    $thrown = $ok ? null : $this->reportFailureException($report, $runId);

                    return [$report, $ok, $thrown];
                }
            );
        } catch (\Throwable $e) {
            $ok = false;
            $thrown = $e instanceof RuntimeException
                ? $e
                : new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $durationSeconds = microtime(true) - $startedAt;
        TimingProbe::mark('php_test_end', [
            'target' => $this->target,
            'runId' => $runId,
            'ok' => $ok,
            'durationMs' => max(0, (int) round($durationSeconds * 1000)),
        ]);

        $lines = $this->buildRunLines(
            target: $report instanceof JsonReportDTO ? $report->target : $this->target,
            runId: $runId,
            ok: $ok,
            durationSeconds: $durationSeconds,
            stats: $report?->stats,
            tests: $report instanceof JsonReportDTO ? $report->tests : [],
            parentTestName: $parentTestName,
            extraLines: $extraLines,
        );

        // Store for inline output (keyed by PHPUnit test ID)
        $currentTestId = $this->testContext->get();

        if ($currentTestId !== null) {
            $entry = new E2EOutputEntryDTO(
                type: 'run',
                target: $this->target,
                runId: $runId,
                ok: $ok,
                durationSeconds: $durationSeconds,
                stats: $report?->stats,
                lines: $lines,
            );

            $this->outputStore->putForTest($currentTestId, $entry);
        } else {
            // Fallback to old behavior if no test context (shouldn't happen in normal flow)
            $this->outputStore->add(
                lines: $lines,
                type: 'run',
                target: $this->target,
                runId: $runId,
                ok: $ok,
                durationSeconds: $durationSeconds,
                stats: $report?->stats,
            );
        }

        if ($thrown instanceof RuntimeException) {
            throw $thrown;
        }
    }

    /**
     * only() — set test filter, returns clone for chaining
     */
    public function only(string $testName): self
    {
        $clone = clone $this;
        $clone->testFilter = $testName;

        return $clone;
    }

    /**
     * Set debug mode.
     */
    public function debug(): self
    {
        CliOptions::$debug = true;

        return $this;
    }

    /**
     * Set browse mode.
     */
    public function browse(): self
    {
        CliOptions::$browse = true;

        return $this;
    }

    /**
     * runTest() — convenience method, equivalent to only($testName)->run()
     */
    public function runTest(string $testName): void
    {
        $this->only($testName)->run();
    }

    /**
     * Get the current test name.
     */
    private function currentTestName(): ?string
    {
        $test = null;

        if (function_exists('test')) {
            try {
                $test = test();
            } catch (\Throwable) {
                $test = null;
            }
        }

        if (is_object($test)) {
            $name = $this->resolveTestName($test);
            if ($name !== null) {
                return $name;
            }
        }

        if (class_exists(TestSuite::class)) {
            try {
                $suite = TestSuite::getInstance();
                if (is_object($suite->test)) {
                    return $this->resolveTestName($suite->test);
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Resolve the test name.
     */
    private function resolveTestName(object $test): ?string
    {
        if (method_exists($test, 'getPrintableTestCaseMethodName')) {
            try {
                $name = $test->getPrintableTestCaseMethodName();
            } catch (\Throwable) {
                $name = null;
            }

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        if (method_exists($test, 'name')) {
            try {
                $rawName = $test->name();

                if (! is_string($rawName) || $rawName === '') {
                    return null;
                }

                $prefix = '__pest_evaluable_';
                if (str_starts_with($rawName, $prefix)) {
                    $rawName = substr($rawName, strlen($prefix));
                }

                $name = str_replace('_', ' ', $rawName);
            } catch (\Throwable) {
                $name = null;
            }

            if ($name !== null && $name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * Build the run lines.
     *
     * @param  array<int, JsonReportTestDTO>  $tests
     * @param  array<int, string>  $extraLines
     * @return array<int, string>
     */
    private function buildRunLines(
        string $target,
        string $runId,
        bool $ok,
        ?float $durationSeconds,
        ?JsonReportStatsDTO $stats,
        array $tests,
        ?string $parentTestName,
        array $extraLines,
    ): array {
        return $this->outputFormatter->buildRunLines(
            target: $target,
            runId: $runId,
            ok: $ok,
            durationSeconds: $durationSeconds,
            stats: $stats,
            tests: $tests,
            parentTestName: $parentTestName,
            extraLines: $extraLines,
        );
    }

    private function reportFailureException(JsonReportDTO $report, string $runId): RuntimeException
    {
        $lines = [];

        foreach ($report->getFailedTests() as $test) {
            $lines[] = $test->name.($test->file ? ' ['.$test->file.']' : '');
        }

        return new RuntimeException(
            "E2E failures for {$this->target} ({$runId}):\n- ".implode("\n- ", $lines)
                ."\n(See inline E2E output above for full details.)"
        );
    }
}
