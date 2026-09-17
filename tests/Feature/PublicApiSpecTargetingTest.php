<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;
use ValcuAndrei\PestE2E\PublicApi\E2E;
use ValcuAndrei\PestE2E\PublicApi\E2ETargetHandle;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

/**
 * Boot a public API with a JS worker that captures the ProcessPlanDTO it
 * receives so the test can assert exactly what pest-e2e would have handed
 * to Playwright. Returns the handle plus a `getCaptured()` closure.
 *
 * @return array{0: E2ETargetHandle, 1: callable(): ?ProcessPlanDTO}
 */
function specTargetingHarness(): array
{
    $captured = null;

    $registry = new TargetRegistry;
    $runIdGenerator = new class implements RunIdGeneratorContract
    {
        public function generate(): string
        {
            return 'run-spec-'.uniqid();
        }
    };
    $worker = new class($captured) implements JsWorkerContract
    {
        public function __construct(public mixed &$capture) {}

        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            $this->capture = $plan;

            $emptyReport = json_encode([
                'schema' => 'pest-e2e.v1',
                'target' => 'frontend',
                'runId' => 'spec',
                'stats' => ['passed' => 1, 'failed' => 0, 'skipped' => 0, 'durationMs' => 1],
                'tests' => [['name' => 'ok', 'status' => 'passed']],
            ], JSON_THROW_ON_ERROR);

            return new ProcessResultDTO(exitCode: 0, stdout: $emptyReport, stderr: '', durationSeconds: 0.01);
        }
    };
    $runner = new E2ERunner(
        registry: $registry,
        planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter),
        jsWorker: $worker,
        reportReader: new JsonReportReader(new PlaywrightParser),
        runIdGenerator: $runIdGenerator,
        reportDirectoryManager: new ReportDirectoryManager,
    );
    $authIssuer = new class implements AuthTicketIssuerContract
    {
        public function issueForUser(mixed $user, array $meta = []): string
        {
            return 'ticket-123';
        }
    };
    $root = new CompositionRoot($registry, $runIdGenerator, $authIssuer, $runner);
    app()->instance(CompositionRoot::class, $root);

    $api = new E2E($root, app());
    $api->target('frontend', fn ($p) => $p->dir(sys_get_temp_dir()));

    $handle = $api->targetHandle('frontend');

    $getCaptured = static fn (): ?ProcessPlanDTO => $worker->capture;

    return [$handle, $getCaptured];
}

it('does not pass a positional spec argument when spec() was not called (legacy path)', function () {
    [$handle, $getCaptured] = specTargetingHarness();

    $handle->run();

    $plan = $getCaptured();
    expect($plan)->not->toBeNull();
    expect($plan->specPath)->toBeNull();
});

it('forwards the pinned spec path through to the ProcessPlanDTO', function () {
    [$handle, $getCaptured] = specTargetingHarness();

    $handle->spec('appearance.spec.ts')->run();

    expect($getCaptured()->specPath)->toBe('appearance.spec.ts');
});

it('preserves both --grep test filter and positional spec when only() and spec() are combined', function () {
    [$handle, $getCaptured] = specTargetingHarness();

    $handle->spec('pageBuilderBoxModel.spec.ts')
        ->only('overlay drag preserves 50 percent width unit')
        ->run();

    $plan = $getCaptured();
    expect($plan->specPath)->toBe('pageBuilderBoxModel.spec.ts')
        ->and($plan->testFilter)->toBe('overlay drag preserves 50 percent width unit');
});

it('normalises Windows backslash separators to forward slashes', function () {
    [$handle, $getCaptured] = specTargetingHarness();

    $handle->spec('nested\\dir\\pageBuilderBoxModel.spec.ts')->run();

    expect($getCaptured()->specPath)->toBe('nested/dir/pageBuilderBoxModel.spec.ts');
});

it('accepts all supported spec/test extensions', function () {
    [$handle, $getCaptured] = specTargetingHarness();

    foreach ([
        'a.spec.ts', 'a.spec.tsx', 'a.spec.js', 'a.spec.mjs', 'a.spec.cjs',
        'a.test.ts', 'a.test.tsx', 'a.test.js', 'a.test.mjs', 'a.test.cjs',
    ] as $path) {
        $handle->spec($path)->run();
        expect($getCaptured()->specPath)->toBe($path);
    }
});

it('rejects an empty spec path', function () {
    [$handle] = specTargetingHarness();

    expect(fn () => $handle->spec(''))
        ->toThrow(InvalidArgumentException::class, 'may not be empty');
});

it('rejects an absolute POSIX spec path', function () {
    [$handle] = specTargetingHarness();

    expect(fn () => $handle->spec('/etc/passwd'))
        ->toThrow(InvalidArgumentException::class, 'relative');
});

it('rejects a Windows drive-letter spec path', function () {
    [$handle] = specTargetingHarness();

    expect(fn () => $handle->spec('C:/tests/foo.spec.ts'))
        ->toThrow(InvalidArgumentException::class, 'drive-letter');
});

it('rejects a spec path containing ".." traversal', function () {
    [$handle] = specTargetingHarness();

    foreach (['../foo.spec.ts', 'sub/../../foo.spec.ts'] as $path) {
        expect(fn () => $handle->spec($path))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('rejects a spec path with an unsupported extension', function () {
    [$handle] = specTargetingHarness();

    foreach (['foo.ts', 'foo.spec', 'foo.spec.txt', 'foo.js', 'foo.spec.py'] as $path) {
        expect(fn () => $handle->spec($path))
            ->toThrow(InvalidArgumentException::class, 'unsupported extension');
    }
});

it('rejects a spec path containing control characters', function () {
    [$handle] = specTargetingHarness();

    expect(fn () => $handle->spec("foo\n.spec.ts"))
        ->toThrow(InvalidArgumentException::class, 'control characters');
});

it('returns a new instance from spec() so state does not leak between chained calls', function () {
    [$handle] = specTargetingHarness();
    $chained = $handle->spec('appearance.spec.ts');

    expect($chained)->not->toBe($handle);
});
