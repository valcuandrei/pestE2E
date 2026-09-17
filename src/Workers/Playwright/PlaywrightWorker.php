<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Workers\Playwright;

use RuntimeException;
use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Support\ParallelWorkerContext;
use ValcuAndrei\PestE2E\Support\ProcessEnvironment;

final class PlaywrightWorker implements JsWorkerContract
{
    public function __construct(
        private readonly JsPackageManager $jsPackageManager,
    ) {}

    /**
     * Run the Playwright worker.
     */
    public function run(ProcessPlanDTO $plan): ProcessResultDTO
    {
        $start = microtime(true);

        $workingDir = $plan->command->workingDirectory;
        $env = $plan->command->getMergedEnv();
        $args = $this->playwrightTestArgs($plan);

        $localPath = $this->jsPackageManager->getLocalBinPath('playwright')
            ?? $this->jsPackageManager->getLocalBinPath('playwright', $workingDir);

        if ($localPath !== null) {
            $process = new Process(
                command: [$localPath, ...$args],
                cwd: $workingDir,
                env: ProcessEnvironment::normalize($env),
            );
            if ($plan->options->timeoutSeconds !== null) {
                $process->setTimeout($plan->options->timeoutSeconds);
            }
            $process->run();
        } else {
            $process = $this->jsPackageManager->runDlx(
                command: ['playwright', ...$args],
                workDir: $workingDir,
                timeout: $plan->options->timeoutSeconds,
                env: $env,
            );
            if ($process === false) {
                throw new RuntimeException('Unable to locate Playwright binary (local node_modules or package manager dlx).');
            }
        }

        $duration = microtime(true) - $start;

        return new ProcessResultDTO(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationSeconds: $duration,
        );
    }

    /**
     * Get the Playwright test arguments.
     *
     * @return list<string>
     */
    private function playwrightTestArgs(ProcessPlanDTO $plan): array
    {
        // pest-e2e's parallelism contract is: outer Pest/ParaTest owns the
        // concurrency; each pest-e2e Playwright invocation runs one selected
        // test with one Playwright worker. Explicitly pin `--workers=1` so
        // Playwright's default of `Math.ceil(os.cpus() / 2)` never fires and
        // spawns extra worker processes on top of the outer ParaTest workers.
        $args = ['test', '--workers=1'];

        if (is_string($plan->testFilter) && $plan->testFilter !== '') {
            $args[] = '--grep';
            $args[] = $this->escapeGrepPattern($plan->testFilter);
        }

        if ($plan->headed) {
            $args[] = '--headed';
        }

        if ($plan->debug) {
            $args[] = '--debug';
        }

        $args[] = '--output';
        $args[] = $this->playwrightOutputDirectory($plan);

        $args[] = '--reporter';
        $args[] = 'json';

        // Positional spec path (F3). When present, Playwright loads only this
        // file instead of walking the whole test directory. Validation is
        // enforced at the public API entry point (`E2ETargetHandle::spec()`),
        // so anything reaching us here is already normalised and safe.
        //
        // Order matters: positional test-file arguments belong at the tail so
        // they are not confused with option values.
        if (is_string($plan->specPath) && $plan->specPath !== '') {
            $args[] = $plan->specPath;
        }

        return $args;
    }

    /**
     * Escape the test filter for Playwright's --grep (regex) so it matches literally.
     */
    private function escapeGrepPattern(string $pattern): string
    {
        return preg_quote($pattern);
    }

    /**
     * Get the Playwright output directory.
     */
    private function playwrightOutputDirectory(ProcessPlanDTO $plan): string
    {
        $targetDir = $plan->reportDirectory;

        if ($targetDir === null) {
            $base = rtrim(sys_get_temp_dir(), '/').'/pest-e2e/playwright-output';
            $targetDir = $base.'/'.md5($plan->command->workingDirectory).ParallelWorkerContext::pathSuffix();
        }

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            throw new RuntimeException("Unable to create Playwright output directory: {$targetDir}");
        }

        return $targetDir;
    }
}
