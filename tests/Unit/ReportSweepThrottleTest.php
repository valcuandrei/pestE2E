<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\ReportRun;
use ValcuAndrei\PestE2E\Support\ReportRunRegistry;
use ValcuAndrei\PestE2E\Tests\Support\ReportTree;

const SWEEP_DAY = 86400;

beforeEach(function (): void {
    ReportDirectoryManager::resetState();
    $this->tree = ReportTree::create('pest-e2e-sweep-test-');
    config()->set('pest-e2e.reports.prune', ['enabled' => true, 'keep_runs' => 500, 'keep_days' => 7, 'grace_minutes' => 60, 'interval_minutes' => 15]);
    $this->stamp = fn (): array => json_decode((string) file_get_contents($this->tree->stateRoot().'/prune.json'), true);
});

afterEach(function (): void {
    ReportDirectoryManager::resetState();
    $this->tree->destroy();
});

it('ships conservative pruning defaults', function (): void {
    $this->refreshApplication();

    expect(config('pest-e2e.reports.prune'))->toBe([
        'enabled' => true,
        'keep_runs' => 500,
        'keep_days' => 7,
        'grace_minutes' => 60,
        'interval_minutes' => 15,
    ]);
});

it('sweeps old runs before a launch when a sweep is due', function (): void {
    $old = $this->tree->finished('old', 8 * SWEEP_DAY);
    $recent = $this->tree->finished('recent', 2 * SWEEP_DAY);
    $current = $this->tree->manager()->begin('frontend', 'current');

    $this->tree->manager()->pruneIfDue($current);

    expect(is_dir($old))->toBeFalse()
        ->and(is_dir($recent))->toBeTrue()
        ->and(is_dir($current->reportDirectory))->toBeTrue()
        ->and(($this->stamp)()['lastSuccessAt'])->toBeGreaterThan(time() - 5);
});

it('does not scan again until the interval has passed', function (): void {
    $first = $this->tree->manager()->begin('frontend', 'first');
    $this->tree->manager()->pruneIfDue($first);
    $stampAfterFirst = ($this->stamp)();

    // Becomes eligible right after the sweep; must wait for the next one.
    $old = $this->tree->finished('old', 8 * SWEEP_DAY);
    $second = $this->tree->manager()->begin('frontend', 'second');
    $this->tree->manager()->pruneIfDue($second);

    expect(is_dir($old))->toBeTrue()
        ->and(($this->stamp)())->toBe($stampAfterFirst);
});

it('sweeps again once the shared timestamp is older than the interval', function (): void {
    $first = $this->tree->manager()->begin('frontend', 'first');
    $this->tree->manager()->pruneIfDue($first);

    $old = $this->tree->finished('old', 8 * SWEEP_DAY);
    $sixteenMinutesAgo = time() - 16 * 60;
    file_put_contents($this->tree->stateRoot().'/prune.json', json_encode(['lastSuccessAt' => $sixteenMinutesAgo, 'lastAttemptAt' => $sixteenMinutesAgo, 'backlog' => false]));

    // A different manager instance, as another Pest worker would have.
    (new ReportDirectoryManager)->pruneIfDue($this->tree->manager()->begin('frontend', 'second'));

    expect(is_dir($old))->toBeFalse();
});

it('honours a custom interval and custom retention', function (): void {
    config()->set('pest-e2e.reports.prune.interval_minutes', 0);
    config()->set('pest-e2e.reports.prune.keep_runs', 1);
    config()->set('pest-e2e.reports.prune.keep_days', 0);
    config()->set('pest-e2e.reports.prune.grace_minutes', 5);

    $newest = $this->tree->finished('newest', 10 * 60);
    $older = $this->tree->finished('older', 20 * 60);
    $insideGrace = $this->tree->finished('inside-grace', 60);

    $this->tree->manager()->pruneIfDue($this->tree->manager()->begin('frontend', 'a'));
    $late = $this->tree->finished('late', 30 * 60);
    $this->tree->manager()->pruneIfDue($this->tree->manager()->begin('frontend', 'b'));

    expect(is_dir($insideGrace))->toBeTrue()
        ->and(is_dir($newest))->toBeFalse()
        ->and(is_dir($older))->toBeFalse()
        ->and(is_dir($late))->toBeFalse();
});

it('keeps every report when pruning is disabled but still clears stale runtime files', function (): void {
    config()->set('pest-e2e.reports.prune.enabled', false);

    $old = $this->tree->finished('old', 30 * SWEEP_DAY);
    $crashed = $this->tree->abandoned('crashed', 10 * 60);

    $this->tree->manager()->pruneIfDue($this->tree->manager()->begin('frontend', 'current'));

    expect(is_dir($old))->toBeTrue()
        ->and(is_dir($crashed->reportDirectory))->toBeTrue()
        ->and(is_dir($crashed->runtimeDirectory))->toBeFalse();
});

it('skips the sweep without waiting when another worker holds the sweep lock', function (): void {
    $old = $this->tree->finished('old', 8 * SWEEP_DAY);
    $current = $this->tree->manager()->begin('frontend', 'current');

    $otherWorker = fopen($this->tree->stateRoot().'/prune.lock', 'c');
    expect(flock($otherWorker, LOCK_EX | LOCK_NB))->toBeTrue();

    $startedAt = microtime(true);
    $this->tree->manager()->pruneIfDue($current);
    $elapsed = microtime(true) - $startedAt;

    flock($otherWorker, LOCK_UN);
    fclose($otherWorker);

    expect(is_dir($old))->toBeTrue()
        ->and(is_file($this->tree->stateRoot().'/prune.json'))->toBeFalse()
        ->and($elapsed)->toBeLessThan(1.0);
});

it('does not fail the run, does not record success and reports once when a sweep cannot run', function (): void {
    $messages = [];
    $realBase = $this->tree->base;
    $link = sys_get_temp_dir().'/pest-e2e-sweep-link-'.bin2hex(random_bytes(6));
    symlink($realBase, $link);
    config()->set('pest-e2e.reports.base_dir', $link);
    config()->set('pest-e2e.reports.prune.interval_minutes', 0);

    $manager = new ReportDirectoryManager(diagnostic: static function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    $manager->pruneIfDue($manager->begin('frontend', 'first'));
    $manager->pruneIfDue($manager->begin('frontend', 'second'));
    (new ReportDirectoryManager(diagnostic: static function (string $message) use (&$messages): void {
        $messages[] = $message;
    }))->pruneIfDue($manager->begin('frontend', 'third'));

    $stamp = json_decode((string) file_get_contents($realBase.'/'.ReportRunRegistry::STATE_DIRECTORY.'/prune.json'), true);
    unlink($link);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('report pruning skipped')
        ->and($stamp['lastSuccessAt'])->toBe(0)
        ->and($stamp['lastAttemptAt'])->toBeGreaterThan(0);
});

it('throttles retries after a failed sweep instead of rescanning on every test', function (): void {
    $link = sys_get_temp_dir().'/pest-e2e-sweep-link-'.bin2hex(random_bytes(6));
    symlink($this->tree->base, $link);
    config()->set('pest-e2e.reports.base_dir', $link);

    $manager = new ReportDirectoryManager(diagnostic: static fn (string $message) => null);
    $manager->pruneIfDue($manager->begin('frontend', 'first'));
    $afterFailure = ($this->stamp)();

    $manager->pruneIfDue($manager->begin('frontend', 'second'));
    unlink($link);

    expect($afterFailure['lastSuccessAt'])->toBe(0)
        ->and(($this->stamp)())->toBe($afterFailure);
});

it('works off a large backlog in bounded passes and comes back sooner while one remains', function (): void {
    foreach (range(1, 230) as $i) {
        $this->tree->finished('old-'.$i, (8 * SWEEP_DAY) + $i);
    }

    $this->tree->manager()->pruneIfDue($this->tree->manager()->begin('frontend', 'current-1'));

    expect(glob($this->tree->base.'/frontend/old-*'))->toHaveCount(30)
        ->and(($this->stamp)()['backlog'])->toBeTrue();

    // Inside the normal 15 minute interval, but a backlog shortens the wait to a minute.
    $twoMinutesAgo = time() - 120;
    file_put_contents($this->tree->stateRoot().'/prune.json', json_encode(['lastSuccessAt' => $twoMinutesAgo, 'lastAttemptAt' => $twoMinutesAgo, 'backlog' => true]));
    $this->tree->manager()->pruneIfDue($this->tree->manager()->begin('frontend', 'current-2'));

    expect(glob($this->tree->base.'/frontend/old-*'))->toHaveCount(0)
        ->and(($this->stamp)()['backlog'])->toBeFalse();
});

it('does nothing for a run that could not be registered', function (): void {
    $old = $this->tree->finished('old', 8 * SWEEP_DAY);
    $unregistered = new ReportRun('frontend', 'x', $this->tree->base, $this->tree->base.'/frontend/x');

    $this->tree->manager()->pruneIfDue($unregistered);

    expect(is_dir($old))->toBeTrue();
});

it('lets several workers allocate, sweep and finish at once without losing an active run', function (): void {
    foreach (range(1, 40) as $i) {
        $this->tree->finished('old-'.$i, (8 * SWEEP_DAY) + $i);
    }

    $heldByThisProcess = $this->tree->active('held-open', 30 * SWEEP_DAY);

    $script = <<<'PHP'
        require $argv[1];
        $app = new Illuminate\Container\Container;
        Illuminate\Container\Container::setInstance($app);
        $app->instance('config', new Illuminate\Config\Repository(['pest-e2e' => ['reports' => [
            'base_dir' => $argv[2],
            'prune' => ['enabled' => true, 'keep_runs' => 0, 'keep_days' => 7, 'grace_minutes' => 60, 'interval_minutes' => 0],
        ]]]));
        $manager = new ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
        for ($i = 0; $i < 25; $i++) {
            $run = $manager->begin('frontend', 'worker-'.$argv[3].'-'.$i);
            @mkdir($run->reportDirectory, 0700, true);
            file_put_contents($run->reportDirectory.'/.last-run.json', '{}');
            $manager->pruneIfDue($run);
            usleep(1000);
            if (! is_file($run->reportDirectory.'/.last-run.json') || ! is_dir($run->runtimeDirectory)) {
                fwrite(STDERR, "own active run was deleted\n");
                exit(3);
            }
            $manager->finish($run, 'passed');
        }
        PHP;

    $scriptFile = $this->tree->base.'/../'.basename($this->tree->base).'-worker.php';
    file_put_contents($scriptFile, "<?php\n".$script);

    $workers = [];

    foreach (range(1, 6) as $worker) {
        $process = new Process([PHP_BINARY, $scriptFile, dirname(__DIR__, 2).'/vendor/autoload.php', $this->tree->base, (string) $worker]);
        $process->start();
        $workers[] = $process;
    }

    foreach ($workers as $process) {
        $process->wait();
    }

    unlink($scriptFile);

    $exitCodes = array_map(static fn (Process $process): int => (int) $process->getExitCode(), $workers);
    $errors = implode('', array_map(static fn (Process $process): string => $process->getErrorOutput(), $workers));

    expect($exitCodes)->toBe([0, 0, 0, 0, 0, 0])
        ->and($errors)->toBe('')
        ->and(glob($this->tree->base.'/frontend/old-*'))->toHaveCount(0)
        ->and(is_dir($heldByThisProcess->reportDirectory))->toBeTrue()
        ->and(glob($this->tree->base.'/frontend/worker-*'))->toHaveCount(150);

    $heldByThisProcess->releaseLock();
});
