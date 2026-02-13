<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use JsonException;
use Pest\TestSuite;
use RuntimeException;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\DTO\AuthPayloadDTO;
use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\DTO\ParamsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;
use ValcuAndrei\PestE2E\Enums\AuthModeType;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;
use ValcuAndrei\PestE2E\Support\CurrentPhpunitTestContext;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

/**
 * Returned by e2e('frontend')
 */
final class E2ETargetHandle
{
    /** @var array<string,string> */
    private array $env = [];

    /** @var array<string,mixed> */
    private array $params = [];

    private ?ProcessOptionsDTO $options = null;

    private ?string $testFilter = null;

    public function __construct(
        private readonly string $target,
        private readonly CompositionRoot $root,
        private readonly ParamsFileWriterContract $paramsFileWriter,
        private readonly ProcessRunner $processRunner,
        private readonly E2EOutputFormatter $outputFormatter,
        private readonly E2EOutputStore $outputStore,
        private readonly CurrentPhpunitTestContext $testContext,
    ) {}

    /**
     * With environment variables.
     *
     * @param  array<string,string>  $env
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

        $report = null;
        $ok = false;
        $thrown = null;

        try {
            $report = $this->root->runner()->run(
                targetName: $this->target,
                env: $this->env,
                params: $this->params,
                options: $this->options,
                runId: $runId,
                testFilter: $this->testFilter,
            );

            $ok = ! $report->hasFailures();

            if (! $ok) {
                $thrown = $this->reportFailureException($report, $runId);
            }
        } catch (RuntimeException $e) {
            $ok = false;
            $thrown = $e;
        }

        $durationSeconds = microtime(true) - $startedAt;

        $lines = $this->buildRunLines(
            target: $report instanceof JsonReportDTO ? $report->target : $this->target,
            runId: $runId,
            ok: $ok,
            durationSeconds: $durationSeconds,
            stats: $report?->stats,
            tests: $report instanceof JsonReportDTO ? $report->tests : [],
            parentTestName: $parentTestName,
            extraLines: [],
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

        if ($thrown instanceof \RuntimeException) {
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
     * runTest() — convenience method, equivalent to only($testName)->run()
     */
    public function runTest(string $testName): void
    {
        $this->only($testName)->run();
    }

    /**
     * call(file, export?, params?) — run standalone JS export
     * Shorthand: call("js/tasks/seed.ts:seedDatabase", [...])
     *
     * Stub for now (public surface reserved).
     *
     * @param  array<string,mixed>  $params
     */
    public function call(string $target, ?string $export = null, array $params = []): void
    {
        $targetConfig = $this->root->registry()->get($this->target);
        $runId = $this->root->generateRunId();
        $startedAt = microtime(true);
        $parentTestName = $this->currentTestName();
        $resolvedTarget = $target;

        /** @var array<string, mixed> */
        $mergedParams = array_replace_recursive($this->params, $params);
        $context = RunContextDTO::make($targetConfig, $runId, $this->env, $mergedParams);

        [$file, $export] = $this->resolveCallTarget($target, $export);

        $paramsDto = new ParamsDTO(
            target: $context->target->name,
            runId: $context->runId,
            params: $context->params,
        );

        $paramsJson = $this->encodeJson($paramsDto);

        $paramsFilePath = $this->paramsFileWriter->write(
            target: $context->target->name,
            runId: $context->runId,
            json: $paramsJson,
        );

        try {
            $harness = $this->callHarnessPath();
            $resolvedTarget = $export !== null ? "{$file}:{$export}" : $file;

            $commandParts = [
                'node',
                escapeshellarg($harness),
                escapeshellarg($file),
            ];

            if ($export !== null) {
                $commandParts[] = escapeshellarg($export);
            }

            $command = implode(' ', $commandParts);

            $commandDto = (new ProcessCommandDTO(
                command: $command,
                workingDirectory: $context->target->dir,
                env: $context->env,
            ))->withInjectedEnv([
                'PEST_E2E_TARGET' => $context->target->name,
                'PEST_E2E_RUN_ID' => $context->runId,
                'PEST_E2E_PARAMS' => $paramsJson,
                'PEST_E2E_PARAMS_FILE' => $paramsFilePath,
            ]);

            $plan = new ProcessPlanDTO(
                command: $commandDto,
                options: $this->options ?? new ProcessOptionsDTO,
                params: $paramsDto,
                paramsJsonInline: $paramsJson,
                paramsJsonFilePath: $paramsFilePath,
            );

            $result = $this->processRunner->run($plan);

            if (! $result->isSuccessful()) {
                throw new RuntimeException(
                    "E2E call failed (exit {$result->exitCode}).\n\n".
                        "TARGET:\n{$resolvedTarget}\n\n".
                        "CMD:\n{$command}\n\n".
                        "CWD:\n{$context->target->dir}\n\n".
                        "STDOUT:\n{$result->stdout}\n\n".
                        "STDERR:\n{$result->stderr}"
                );
            }

            $durationSeconds = microtime(true) - $startedAt;
            $lines = $this->buildCallLines(
                target: $this->target,
                resolvedTarget: $resolvedTarget,
                runId: $runId,
                ok: true,
                durationSeconds: $durationSeconds,
                parentTestName: $parentTestName,
                extraLines: [],
            );

            // Store for inline output (keyed by PHPUnit test ID)
            $currentTestId = $this->testContext->get();

            if ($currentTestId !== null) {
                $entry = new E2EOutputEntryDTO(
                    type: 'call',
                    target: $this->target,
                    runId: $runId,
                    ok: true,
                    durationSeconds: $durationSeconds,
                    stats: null,
                    lines: $lines,
                );

                $this->outputStore->putForTest($currentTestId, $entry);
            } else {
                // Fallback to old behavior
                $this->outputStore->add(
                    lines: $lines,
                    type: 'call',
                    target: $this->target,
                    runId: $runId,
                    ok: true,
                    durationSeconds: $durationSeconds,
                    stats: null,
                );
            }
        } catch (RuntimeException $exception) {
            $durationSeconds = microtime(true) - $startedAt;
            $lines = $this->buildCallLines(
                target: $this->target,
                resolvedTarget: $resolvedTarget,
                runId: $runId,
                ok: false,
                durationSeconds: $durationSeconds,
                parentTestName: $parentTestName,
                extraLines: $this->exceptionLines($exception),
            );

            // Store for inline output (keyed by PHPUnit test ID)
            $currentTestId = $this->testContext->get();

            if ($currentTestId !== null) {
                $entry = new E2EOutputEntryDTO(
                    type: 'call',
                    target: $this->target,
                    runId: $runId,
                    ok: false,
                    durationSeconds: $durationSeconds,
                    stats: null,
                    lines: $lines,
                );

                $this->outputStore->putForTest($currentTestId, $entry);
            } else {
                // Fallback to old behavior
                $this->outputStore->add(
                    lines: $lines,
                    type: 'call',
                    target: $this->target,
                    runId: $runId,
                    ok: false,
                    durationSeconds: $durationSeconds,
                    stats: null,
                );
            }

            throw $exception;
        } finally {
            @unlink($paramsFilePath);
        }
    }

    /**
     * Resolve the call target and export name.
     *
     * @return array{0:string,1:?string}
     */
    private function resolveCallTarget(string $target, ?string $export): array
    {
        if ($export !== null) {
            return [$target, $export];
        }

        $pos = strrpos($target, ':');

        if ($pos === false) {
            return [$target, null];
        }

        $file = substr($target, 0, $pos);
        $candidate = substr($target, $pos + 1);

        if ($file === '' || $candidate === '') {
            return [$target, null];
        }

        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return [$target, null];
        }

        return [$file, $candidate];
    }

    /**
     * Get the call harness path.
     */
    private function callHarnessPath(): string
    {
        $path = dirname(__DIR__, 2).'/resources/node/call.mjs';

        if (! is_file($path)) {
            throw new RuntimeException("E2E call harness not found at {$path}");
        }

        return $path;
    }

    /**
     * Encode the params to JSON.
     *
     * @throws JsonException
     */
    private function encodeJson(ParamsDTO $paramsDto): string
    {
        return json_encode($paramsDto->toArray(), JSON_THROW_ON_ERROR);
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

    /**
     * Build the call lines.
     *
     * @param  array<int, string>  $extraLines
     * @return array<int, string>
     */
    private function buildCallLines(
        string $target,
        string $resolvedTarget,
        string $runId,
        bool $ok,
        ?float $durationSeconds,
        ?string $parentTestName,
        array $extraLines,
    ): array {
        return $this->outputFormatter->buildCallLines(
            target: $target,
            resolvedTarget: $resolvedTarget,
            runId: $runId,
            ok: $ok,
            durationSeconds: $durationSeconds,
            parentTestName: $parentTestName,
            extraLines: $extraLines,
        );
    }

    /**
     * Build the exception lines.
     *
     * @return array<int, string>
     */
    private function exceptionLines(RuntimeException $exception): array
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return [];
        }

        return array_values(array_filter(
            explode("\n", $message),
            static fn (string $line): bool => $line !== '',
        ));
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
