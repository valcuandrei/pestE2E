<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Workers\Playwright\PlaywrightWorker;

function buildPlaywrightArgs(ProcessPlanDTO $plan): array
{
    $worker = new PlaywrightWorker(new JsPackageManager);
    $method = new ReflectionMethod(PlaywrightWorker::class, 'playwrightTestArgs');
    $method->setAccessible(true);

    /** @var list<string> $args */
    $args = $method->invoke($worker, $plan);

    return $args;
}

it('passes the process plan report directory to Playwright output', function (): void {
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        reportDirectory: '/tmp/pest-e2e/reports/frontend/run-123',
    );

    $args = buildPlaywrightArgs($plan);

    expect($args)->toContain('--output')
        ->and($args[array_search('--output', $args, true) + 1])->toBe('/tmp/pest-e2e/reports/frontend/run-123');
});

it('pins Playwright to a single worker so outer Pest parallelism is the sole source of concurrency', function (): void {
    // The whole point of pest-e2e is that outer ParaTest/Pest owns the
    // parallelism. Each individual pest-e2e invocation runs one selected
    // Playwright test with one worker. Without this pin, Playwright defaults
    // to `Math.ceil(os.cpus().length / 2)` and can start a worker pool the
    // outer runner doesn't know about — measured on a 12-thread box that
    // reported `workers: 6` at every invocation.
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        reportDirectory: '/tmp/pest-e2e/reports/frontend/run-123',
    );

    $args = buildPlaywrightArgs($plan);

    expect($args)->toContain('--workers=1');

    // `--workers=1` must be present regardless of headed/debug/testFilter state.
    $planWithFilter = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        reportDirectory: '/tmp/pest-e2e/reports/frontend/run-456',
        testFilter: 'my test',
        headed: true,
        debug: true,
    );

    expect(buildPlaywrightArgs($planWithFilter))->toContain('--workers=1');
});

it('omits the positional spec argument when no spec is pinned (legacy behaviour)', function (): void {
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        testFilter: 'switch to dark mode',
    );

    $args = buildPlaywrightArgs($plan);

    // No unknown positional args — every entry after `test` should either be
    // a known Playwright flag or the value of the immediately-preceding flag.
    // In practice: everything is prefixed with `--` except the flag values
    // (grep pattern, output path, reporter name).
    $positionals = array_values(array_filter(
        $args,
        static fn (string $a, int $i) => $i > 0
            && ! str_starts_with($a, '--')
            && (! isset($args[$i - 1]) || ! in_array($args[$i - 1], ['--grep', '--output', '--reporter'], true)),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($positionals)->toBeEmpty();
});

it('appends the pinned spec path as the tail positional argument to playwright test', function (): void {
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        testFilter: 'switch to dark mode',
        specPath: 'appearance.spec.ts',
    );

    $args = buildPlaywrightArgs($plan);

    // Tail positional argument.
    expect(end($args))->toBe('appearance.spec.ts');

    // Not confused with a flag value: nothing between the last `--flag value`
    // pair and the spec path.
    $reporterIndex = array_search('--reporter', $args, true);
    expect($args[$reporterIndex + 1])->toBe('json');
    expect($args[$reporterIndex + 2])->toBe('appearance.spec.ts');
});

it('accepts nested spec paths under subdirectories', function (): void {
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        specPath: 'nested/dir/pageBuilderBoxModel.spec.ts',
    );

    $args = buildPlaywrightArgs($plan);

    expect(end($args))->toBe('nested/dir/pageBuilderBoxModel.spec.ts');
});

it('preserves the existing headed/debug/output/reporter/grep contract', function (): void {
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        reportDirectory: '/tmp/pest-e2e/reports/frontend/run-789',
        testFilter: 'switch to dark mode',
        headed: true,
        debug: true,
    );

    $args = buildPlaywrightArgs($plan);

    expect($args)->toContain('--headed')
        ->and($args)->toContain('--debug')
        ->and($args)->toContain('--reporter')
        ->and($args[array_search('--reporter', $args, true) + 1])->toBe('json')
        ->and($args)->toContain('--grep');

    $grepIndex = array_search('--grep', $args, true);
    // Escaped for regex — preg_quote of "switch to dark mode".
    expect($args[$grepIndex + 1])->toBe(preg_quote('switch to dark mode'));
});
