<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use RuntimeException;
use Throwable;
use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\AdmissionDecisionDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportErrorDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Enums\TestStatusType;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Support\AdmissionController;
use ValcuAndrei\PestE2E\Support\AdmissionControllerFactory;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\WarmBrowserManager;

/**
 * @internal
 */
final class E2ERunner
{
    /**
     * Create a new E2ERunner instance.
     *
     * @param  AdmissionController|null  $admissionController  Optional injected controller — tests can pass a
     *                                                         fake-sampler-backed instance. Production callers pass
     *                                                         null, which resolves the default from config.
     */
    public function __construct(private readonly TargetRegistry $registry, private readonly ProcessPlanBuilder $planBuilder, private readonly JsWorkerContract $jsWorker, private readonly JsonReportReader $reportReader, private readonly RunIdGeneratorContract $runIdGenerator, private readonly ReportDirectoryManager $reportDirectoryManager, private ?AdmissionController $admissionController = null) {}

    /**
     * Run the E2E test suite for a target.
     *
     * @param  array<string,string|null>  $env
     * @param  array<string,mixed>  $params
     * @param  ProcessOptionsDTO|null  $options  (optional) process options
     * @param  string|null  $testFilter  (optional) test filter
     * @param  string|null  $specPath  (optional) validated spec file path,
     *                                 relative to the target `dir`. When set,
     *                                 Playwright receives it as a positional
     *                                 argument and skips full-project discovery.
     */
    public function run(
        string $targetName,
        array $env = [],
        array $params = [],
        ?ProcessOptionsDTO $options = null,
        ?string $runId = null,
        ?string $testFilter = null,
        ?string $specPath = null,
    ): JsonReportDTO {
        $target = $this->registry->get($targetName);
        $runId ??= $this->runIdGenerator->generate();
        $resolvedReportDir = $this->reportDirectoryManager->prepare(
            target: $target->name,
            runId: $runId,
        );
        // Adaptive resource admission — gates every heavyweight E2E execution
        // on measured host pressure. `null` decision means the controller is
        // disabled or unavailable; either way, `admit()` blocks (sleeps) until
        // pressure drops or the max-wait ceiling elapses. Fail-open by design.
        $decision = $this->admissionController()?->admit();

        // F5 warm-browser: propagate this worker's persistent Playwright
        // launch-server endpoint to the child so Playwright connects to it
        // instead of launching a fresh chromium. Each pest test still gets
        // its own isolated browser context (per Playwright's per-test
        // context model), so no cookie / localStorage / storage-state
        // leakage between tests.
        $envWithWarmBrowser = $env;

        if ($this->warmBrowserEnabled()) {
            try {
                $wsEndpoint = WarmBrowserManager::forCurrentWorker(
                    startupTimeoutSeconds: $this->warmBrowserStartupTimeoutSeconds(),
                )->endpoint();
                $envWithWarmBrowser['PEST_E2E_WARM_WS_ENDPOINT'] = $wsEndpoint;
            } catch (Throwable $warmBrowserError) {
                // Fail-open: if the warm-browser server cannot be launched
                // (e.g. Playwright not installed in the consumer, or a
                // permissions issue), fall back to the classic cold-start
                // path. The exception is surfaced as a diagnostic to
                // stderr but does not abort the run.
                fwrite(STDERR, "\npest-e2e: warm-browser unavailable, falling back to cold launch — {$warmBrowserError->getMessage()}\n");
            }
        }

        $context = RunContextDTO::make($target, $runId, $envWithWarmBrowser, $params, $testFilter, $resolvedReportDir, $specPath);
        $plan = $this->planBuilder->build($context, $options);

        // Instrumentation: emit a single-line diagnostic when admission or
        // warm-browser actually did something interesting (queued waits, or
        // an admission wait > 100ms). Cheap to grep, invisible under normal
        // low-pressure runs so we don't spam pest output.
        if ($decision instanceof AdmissionDecisionDTO && ($decision->waitedSeconds > 0.1 || $decision->reason !== 'immediate' && $decision->reason !== 'disabled')) {
            fwrite(STDERR, sprintf(
                "\npest-e2e admission: %s (waited=%.2fs checks=%d cpu=%s%% mem=%s%%)\n",
                $decision->reason,
                $decision->waitedSeconds,
                $decision->checks,
                $decision->cpuUsedPct === null ? 'n/a' : number_format($decision->cpuUsedPct, 1),
                $decision->memoryUsedPct === null ? 'n/a' : number_format($decision->memoryUsedPct, 1),
            ));
        }

        $runResult = $this->jsWorker->run($plan);

        try {
            $report = $this->reportReader->readForRun($context, $runResult->stdout);

            if ($runResult->exitCode !== 0 && $report->isSuccessful()) {
                $report = $report->withStats($report->stats->withFailed(1));
                $message = $this->formatProcessFailureMessage($runResult->exitCode, $runResult->stderr, $runResult->stdout);

                $synthetic = new JsonReportTestDTO(
                    name: 'E2E process failed',
                    status: TestStatusType::FAILED,
                    error: new JsonReportErrorDTO($message),
                );

                return $report->withTests(array_merge($report->getTests(), [$synthetic]));
            }
        } catch (Throwable $reportException) {
            throw new RuntimeException("E2E command failed (exit {$runResult->exitCode}).\n\n".
                "TARGET:\n{$target->name}\n\n".
                (in_array($testFilter, [null, '', '0'], true) ? '' : "FILTER:\n{$testFilter}\n\n").
                "RUN_ID:\n{$runId}\n\n".
                "CMD:\n{$plan->commandPreview}\n\n".
                "CWD:\n{$plan->command->workingDirectory}\n\n".
                "STDOUT:\n{$runResult->stdout}\n\n".
                "STDERR:\n{$runResult->stderr}\n\n".
                "REPORT ERROR:\n{$reportException->getMessage()}", $reportException->getCode(), $reportException);
        }

        return $report;
    }

    private function admissionController(): ?AdmissionController
    {
        if ($this->admissionController instanceof AdmissionController) {
            return $this->admissionController;
        }

        if (! function_exists('config')) {
            return null;
        }

        $enabled = (bool) config('pest-e2e.admission.enabled', true);

        if (! $enabled) {
            return null;
        }

        $this->admissionController = AdmissionControllerFactory::make();

        return $this->admissionController;
    }

    private function warmBrowserEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        return (bool) config('pest-e2e.warm_browser.enabled', true);
    }

    private function warmBrowserStartupTimeoutSeconds(): int
    {
        if (! function_exists('config')) {
            return 30;
        }

        $value = config('pest-e2e.warm_browser.startup_timeout_seconds', 30);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return 30;
    }

    private function formatProcessFailureMessage(int $exitCode, string $stderr, string $stdout): string
    {
        $stderr = trim($stderr);
        $stdout = trim($stdout);
        $body = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : '(no output)');
        $maxChars = 4000;

        if (mb_strlen($body) > $maxChars) {
            $body = mb_substr($body, -$maxChars);
            $body = "[output truncated to last {$maxChars} chars]\n".$body;
        }

        return "E2E command exited with code {$exitCode}.\n\n".$body;
    }
}
