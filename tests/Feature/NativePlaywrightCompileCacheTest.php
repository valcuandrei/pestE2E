<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Support\NodeCompileCache;
use ValcuAndrei\PestE2E\Workers\Playwright\PlaywrightWorker;

/*
 * Runs real, unmodified `@playwright/test` specs through PlaywrightWorker with
 * and without the compile cache and requires identical native results.
 *
 * Needs an installed `@playwright/test` somewhere above the package (true when
 * the package is checked out inside a consumer project, as with a Composer
 * path repository). Skipped otherwise — the package itself has no JS
 * dependencies. The specs use no browser.
 */

/**
 * Closest `node_modules` directory above the package that contains Playwright Test.
 */
function nearestPlaywrightNodeModules(): ?string
{
    $directory = dirname(__DIR__, 2);

    while (true) {
        if (is_file($directory.'/node_modules/@playwright/test/package.json') && is_file($directory.'/node_modules/.bin/playwright')) {
            return $directory.'/node_modules';
        }

        $parent = dirname($directory);

        if ($parent === $directory) {
            return null;
        }

        $directory = $parent;
    }
}

function installedNodeVersion(): ?string
{
    $process = new Process(['node', '--version']);
    $process->run();

    return $process->isSuccessful() ? ltrim(trim($process->getOutput()), 'v') : null;
}

/**
 * Run one native spec and reduce Playwright's JSON report to what must not change.
 *
 * @return array{exitCode: int, tests: list<string>, errors: list<string>}
 */
function runNativeSpec(string $project, string $spec, NodeCompileCache $compileCache): array
{
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: $project),
        options: new ProcessOptionsDTO,
        reportDirectory: $project.'/report',
        specPath: $spec,
    );

    $result = (new PlaywrightWorker(new JsPackageManager, $compileCache))->run($plan);

    /** @var array{suites: list<array<string, mixed>>, errors: list<array{message: string}>} $report */
    $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);

    $tests = [];
    $errors = array_map(static fn (array $error): string => strtok($error['message'], "\n") ?: '', $report['errors']);

    $walk = static function (array $suite) use (&$walk, &$tests, &$errors): void {
        foreach ($suite['specs'] ?? [] as $spec) {
            foreach ($spec['tests'] as $test) {
                $attempts = array_map(static fn (array $attempt): string => $attempt['status'].'@retry'.$attempt['retry'], $test['results']);
                $tests[] = $spec['title'].' => '.$test['status'].' ['.implode(', ', $attempts).']';

                foreach ($test['results'] as $attempt) {
                    foreach ($attempt['errors'] ?? [] as $error) {
                        $errors[] = preg_replace('/\e\[[0-9;]*m/', '', strtok($error['message'], "\n") ?: '') ?? '';
                    }
                }
            }
        }

        foreach ($suite['suites'] ?? [] as $child) {
            $walk($child);
        }
    };

    foreach ($report['suites'] as $suite) {
        $walk($suite);
    }

    sort($tests);

    return ['exitCode' => $result->exitCode, 'tests' => $tests, 'errors' => $errors];
}

beforeEach(function (): void {
    $nodeModules = nearestPlaywrightNodeModules();
    $this->nodeVersion = installedNodeVersion();

    if ($nodeModules === null || $this->nodeVersion === null) {
        $this->markTestSkipped('Needs node and an installed @playwright/test above the package directory.');
    }

    NodeCompileCache::resetState();
    $this->project = sys_get_temp_dir().'/pest-e2e-ncc-native-'.bin2hex(random_bytes(6));
    mkdir($this->project, 0700, true);
    symlink($nodeModules, $this->project.'/node_modules');
    $this->cacheDirectory = $this->project.'/compile-cache';

    file_put_contents($this->project.'/playwright.config.cjs', <<<'JS'
        module.exports = { testDir: '.', timeout: 5000, workers: 1, retries: 1 };
        JS);

    file_put_contents($this->project.'/passing.spec.cjs', <<<'JS'
        const { test: base, expect } = require('@playwright/test');
        const test = base.extend({ greeting: async ({}, use) => { await use('hello'); } });
        test.describe('native features', () => {
            let prepared = false;
            test.beforeEach(async () => { prepared = true; });
            test('uses hooks and a custom fixture', async ({ greeting }) => {
                expect(prepared).toBe(true);
                expect(greeting).toBe('hello');
            });
            test('passes on its native retry', async ({}, testInfo) => {
                expect(testInfo.retry).toBe(1);
            });
            test.skip('is skipped natively', async () => {});
        });
        JS);

    file_put_contents($this->project.'/failing.spec.cjs', <<<'JS'
        const { test, expect } = require('@playwright/test');
        test('reports a native assertion failure', async () => {
            expect('actual value').toBe('expected value');
        });
        JS);

    $this->cached = new NodeCompileCache(directory: $this->cacheDirectory);
    $this->uncached = new NodeCompileCache(nodeVersionResolver: static fn (): ?string => null, directory: $this->cacheDirectory);
});

afterEach(function (): void {
    NodeCompileCache::resetState();

    if (isset($this->project)) {
        @unlink($this->project.'/node_modules');
        exec('rm -rf '.escapeshellarg($this->project));
    }
});

it('runs native specs to the same results with and without the compile cache', function (): void {
    $uncached = runNativeSpec($this->project, 'passing.spec.cjs', $this->uncached);
    $firstCached = runNativeSpec($this->project, 'passing.spec.cjs', $this->cached);
    $secondCached = runNativeSpec($this->project, 'passing.spec.cjs', $this->cached);

    expect($uncached)->toBe([
        'exitCode' => 0,
        'tests' => [
            'is skipped natively => skipped [skipped@retry0]',
            'passes on its native retry => flaky [failed@retry0, passed@retry1]',
            'uses hooks and a custom fixture => expected [passed@retry0]',
        ],
        'errors' => ['Error: expect(received).toBe(expected) // Object.is equality'],
    ])->and($firstCached)->toBe($uncached)
        ->and($secondCached)->toBe($uncached);
});

it('reports a native failure with the same exit status and error with and without the compile cache', function (): void {
    $uncached = runNativeSpec($this->project, 'failing.spec.cjs', $this->uncached);
    $cached = runNativeSpec($this->project, 'failing.spec.cjs', $this->cached);
    $cachedAgain = runNativeSpec($this->project, 'failing.spec.cjs', $this->cached);

    expect($uncached['exitCode'])->toBe(1)
        ->and($uncached['tests'])->toBe(['reports a native assertion failure => unexpected [failed@retry0, failed@retry1]'])
        ->and($uncached['errors'])->toBe(array_fill(0, 2, 'Error: expect(received).toBe(expected) // Object.is equality'))
        ->and($cached)->toBe($uncached)
        ->and($cachedAgain)->toBe($uncached);
});

it('lets Node populate the cache directory on supported runtimes and leaves it alone on older ones', function (): void {
    runNativeSpec($this->project, 'passing.spec.cjs', $this->uncached);

    expect(file_exists($this->cacheDirectory))->toBeFalse();

    runNativeSpec($this->project, 'passing.spec.cjs', $this->cached);

    $entries = is_dir($this->cacheDirectory) ? glob($this->cacheDirectory.'/*/*') : [];

    if (NodeCompileCache::supportsCompileCache($this->nodeVersion)) {
        expect($entries)->not->toBeEmpty();
    } else {
        expect(file_exists($this->cacheDirectory))->toBeFalse();
    }
});
