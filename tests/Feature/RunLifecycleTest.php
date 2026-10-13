<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\ReportRunRegistry;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;
use ValcuAndrei\PestE2E\Tests\Support\ReportTree;
use ValcuAndrei\PestE2E\Tests\Support\TemporaryDirectories;

/**
 * A stand-in for the Playwright process that records what it could see while "running"
 * and behaves like the real thing on disk: it empties its output directory and has the
 * harness write a storage-state file.
 */
final class RecordingJsWorker implements JsWorkerContract
{
    /** @var array<string, mixed> */
    public array $seen = [];

    public function __construct(private readonly ReportTree $tree, private readonly ?Throwable $throws = null, private readonly bool $legacyHarness = false, private readonly int $exitCode = 0) {}

    public function run(ProcessPlanDTO $plan): ProcessResultDTO
    {
        $env = $plan->command->getMergedEnv();
        $runtime = $env['PEST_E2E_RUNTIME_DIR'] ?? null;
        $reportDirectory = (string) $plan->reportDirectory;
        $stateDirectory = dirname((string) $runtime);

        $this->tree->playwrightWritesOutput($reportDirectory);

        if ($this->legacyHarness) {
            $legacy = $plan->command->workingDirectory.'/.pest-e2e/'.$env['PEST_E2E_RUN_ID'];
            mkdir($legacy, 0700, true);
            file_put_contents($legacy.'/storageState.json', '{"cookies":[{"name":"laravel_session","value":"secret"}]}');
        } elseif (is_string($runtime)) {
            file_put_contents($runtime.'/storageState.json', '{"cookies":[{"name":"laravel_session","value":"secret"}]}');
        }

        $this->seen = [
            'runtimeDirectory' => $runtime,
            'runtimeDirectoryExisted' => is_string($runtime) && is_dir($runtime),
            'runtimeIsPrivate' => is_string($runtime) ? (fileperms($runtime) & 0777) : null,
            'activeWhileRunning' => (new ReportRunRegistry)->lockIfInactive($stateDirectory) === null,
            'sweepStampWritten' => is_file($this->tree->stateRoot().'/prune.json'),
            'params' => $plan->params?->params,
        ];

        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        $report = $this->exitCode === 0
            ? JsonReportDTO::fakeWithPassedTest()
            : JsonReportDTO::fakeWithFailedTest();

        return new ProcessResultDTO(
            exitCode: $this->exitCode,
            stdout: $report->withTarget('frontend')->withRunId((string) $env['PEST_E2E_RUN_ID'])->toJson(),
            stderr: '',
            durationSeconds: 0.01,
        );
    }
}

function lifecycleRunner(ReportTree $tree, string $targetDirectory, JsWorkerContract $worker, string $runId): E2ERunner
{
    $registry = new TargetRegistry;
    $registry->put(new TargetConfigDTO(name: 'frontend', dir: $targetDirectory, env: [], params: []));

    return new E2ERunner(
        registry: $registry,
        planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter),
        jsWorker: $worker,
        reportReader: new JsonReportReader(new PlaywrightParser),
        runIdGenerator: new FixedRunIdGenerator($runId),
        reportDirectoryManager: new ReportDirectoryManager,
    );
}

function runRecord(ReportTree $tree, string $runId): array
{
    return json_decode((string) file_get_contents(ReportRunRegistry::stateDirectory($tree->base, 'frontend', $runId).'/run.json'), true);
}

beforeEach(function (): void {
    ReportDirectoryManager::resetState();
    $this->tree = ReportTree::create('pest-e2e-lifecycle-test-');
    $this->targetDirectory = TemporaryDirectories::create('pest-e2e-lifecycle-target-');
});

afterEach(function (): void {
    $this->tree->destroy();
    TemporaryDirectories::remove($this->targetDirectory);
});

it('gives the JS process a private per-run runtime directory outside the target directory', function (): void {
    $worker = new RecordingJsWorker($this->tree);

    lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend');

    expect($worker->seen['runtimeDirectory'])->toBe($this->tree->base.'/.pest-e2e-state/frontend/run-1/runtime')
        ->and($worker->seen['runtimeDirectoryExisted'])->toBeTrue()
        ->and($worker->seen['runtimeIsPrivate'])->toBe(0700)
        ->and(str_starts_with($worker->seen['runtimeDirectory'], $this->targetDirectory))->toBeFalse()
        ->and(file_exists($this->targetDirectory.'/.pest-e2e'))->toBeFalse();
});

it('uses a different runtime directory for every run', function (): void {
    $first = new RecordingJsWorker($this->tree);
    $second = new RecordingJsWorker($this->tree);

    lifecycleRunner($this->tree, $this->targetDirectory, $first, 'run-user-1')->run('frontend');
    lifecycleRunner($this->tree, $this->targetDirectory, $second, 'run-user-2')->run('frontend');

    expect($first->seen['runtimeDirectory'])->not->toBe($second->seen['runtimeDirectory'])
        ->and(is_dir($first->seen['runtimeDirectory']))->toBeFalse();
});

it('marks the run active while Playwright runs and releases it afterwards', function (): void {
    $worker = new RecordingJsWorker($this->tree);

    lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend');

    $lock = (new ReportRunRegistry)->lockIfInactive(ReportRunRegistry::stateDirectory($this->tree->base, 'frontend', 'run-1'));

    expect($worker->seen['activeWhileRunning'])->toBeTrue()
        ->and($lock)->not->toBeNull()
        ->and(runRecord($this->tree, 'run-1')['state'])->toBe('passed');

    (new ReportRunRegistry)->unlock($lock);
});

it('checks pruning before launch and only then issues late params such as the auth ticket', function (): void {
    $old = $this->tree->finished('old', 8 * 86400);
    $timeline = [];
    $worker = new RecordingJsWorker($this->tree);

    lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend', lateParams: function () use (&$timeline, $old): array {
        $timeline[] = 'ticket issued; sweep already done: '.var_export(! is_dir($old) && is_file($this->tree->stateRoot().'/prune.json'), true);

        return ['auth' => ['ticket' => 'issued-after-pruning']];
    });

    expect($timeline)->toBe(['ticket issued; sweep already done: true'])
        ->and($worker->seen['sweepStampWritten'])->toBeTrue()
        ->and($worker->seen['params']['auth']['ticket'])->toBe('issued-after-pruning');
});

it('deletes the storage state after a successful run and keeps the report', function (): void {
    $worker = new RecordingJsWorker($this->tree);

    lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend');

    $reportDirectory = $this->tree->base.'/frontend/run-1';

    expect(is_dir($worker->seen['runtimeDirectory']))->toBeFalse()
        ->and(is_file($reportDirectory.'/spec-test-chromium/trace.zip'))->toBeTrue()
        ->and(TemporaryDirectories::filesContaining($this->tree->base, 'laravel_session'))->toBe([]);
});

it('deletes the storage state after a failed run', function (): void {
    $worker = new RecordingJsWorker($this->tree, exitCode: 1);

    $report = lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend');

    expect($report->hasFailures())->toBeTrue()
        ->and(is_dir($worker->seen['runtimeDirectory']))->toBeFalse()
        ->and(runRecord($this->tree, 'run-1')['state'])->toBe('failed')
        ->and(is_file($this->tree->base.'/frontend/run-1/spec-test-chromium/trace.zip'))->toBeTrue();
});

it('deletes the storage state and releases the run when launching throws', function (): void {
    $worker = new RecordingJsWorker($this->tree, throws: new RuntimeException('process could not start'));

    expect(fn () => lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend'))
        ->toThrow(RuntimeException::class, 'process could not start');

    $lock = (new ReportRunRegistry)->lockIfInactive(ReportRunRegistry::stateDirectory($this->tree->base, 'frontend', 'run-1'));

    expect(is_dir($worker->seen['runtimeDirectory']))->toBeFalse()
        ->and($lock)->not->toBeNull()
        ->and(runRecord($this->tree, 'run-1')['state'])->toBe('interrupted');

    (new ReportRunRegistry)->unlock($lock);
});

it('deletes the storage state when late params throw before launch', function (): void {
    $worker = new RecordingJsWorker($this->tree);

    expect(fn () => lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend', lateParams: fn () => throw new LogicException('no ticket')))
        ->toThrow(LogicException::class);

    expect(is_dir($this->tree->base.'/.pest-e2e-state/frontend/run-1/runtime'))->toBeFalse()
        ->and(runRecord($this->tree, 'run-1')['state'])->toBe('interrupted');
});

it('clears the per-run state an older published harness writes into the target directory', function (): void {
    $worker = new RecordingJsWorker($this->tree, legacyHarness: true);

    lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend');

    expect(file_exists($this->targetDirectory.'/.pest-e2e/run-1'))->toBeFalse();
});

it('leaves other entries of the target state directory untouched', function (): void {
    mkdir($this->targetDirectory.'/.pest-e2e/0123456789abcdef', 0700, true);
    file_put_contents($this->targetDirectory.'/.pest-e2e/0123456789abcdef/storageState.json', '{}');
    $worker = new RecordingJsWorker($this->tree, legacyHarness: true);

    lifecycleRunner($this->tree, $this->targetDirectory, $worker, 'run-1')->run('frontend');

    // Historical backlog is a separate, explicitly approved cleanup.
    expect(is_file($this->targetDirectory.'/.pest-e2e/0123456789abcdef/storageState.json'))->toBeTrue();
});

it('keeps unexpected files an older harness directory may contain', function (): void {
    $manager = new ReportDirectoryManager;
    $run = $manager->begin('frontend', 'run-1');
    mkdir($this->targetDirectory.'/.pest-e2e/run-1', 0700, true);
    file_put_contents($this->targetDirectory.'/.pest-e2e/run-1/storageState.json', '{}');
    file_put_contents($this->targetDirectory.'/.pest-e2e/run-1/notes.txt', 'mine');

    $manager->finish($run, 'passed', $this->targetDirectory);

    expect(is_file($this->targetDirectory.'/.pest-e2e/run-1/storageState.json'))->toBeFalse()
        ->and(is_file($this->targetDirectory.'/.pest-e2e/run-1/notes.txt'))->toBeTrue();
});

it('does not follow a symlinked target state directory', function (): void {
    $elsewhere = TemporaryDirectories::create('pest-e2e-lifecycle-elsewhere-');
    mkdir($elsewhere.'/run-1', 0700, true);
    file_put_contents($elsewhere.'/run-1/storageState.json', '{}');
    symlink($elsewhere, $this->targetDirectory.'/.pest-e2e');

    $manager = new ReportDirectoryManager;
    $manager->finish($manager->begin('frontend', 'run-1'), 'passed', $this->targetDirectory);

    expect(is_file($elsewhere.'/run-1/storageState.json'))->toBeTrue();

    TemporaryDirectories::remove($elsewhere);
});

it('ignores run ids that could escape the target state directory', function (string $runId): void {
    mkdir($this->targetDirectory.'/.pest-e2e', 0700, true);
    file_put_contents($this->targetDirectory.'/storageState.json', 'not ours');

    $manager = new ReportDirectoryManager;
    $manager->finish($manager->begin('frontend', $runId), 'passed', $this->targetDirectory);

    expect(is_file($this->targetDirectory.'/storageState.json'))->toBeTrue();
})->with(['..', '../..', '.', 'a/../..']);

it('runs normally when a sweep cannot be performed', function (): void {
    $link = sys_get_temp_dir().'/pest-e2e-lifecycle-link-'.bin2hex(random_bytes(6));
    symlink($this->tree->base, $link);
    config()->set('pest-e2e.reports.base_dir', $link);
    $messages = [];

    $registry = new TargetRegistry;
    $registry->put(new TargetConfigDTO(name: 'frontend', dir: $this->targetDirectory, env: [], params: []));
    $worker = new RecordingJsWorker(new ReportTree($link));
    $runner = new E2ERunner(
        registry: $registry,
        planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter),
        jsWorker: $worker,
        reportReader: new JsonReportReader(new PlaywrightParser),
        runIdGenerator: new FixedRunIdGenerator('run-1'),
        reportDirectoryManager: new ReportDirectoryManager(diagnostic: static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }),
    );

    $report = $runner->run('frontend');
    unlink($link);

    expect($report->hasFailures())->toBeFalse()
        ->and($messages)->toHaveCount(1);
});
