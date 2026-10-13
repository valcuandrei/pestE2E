<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;
use ValcuAndrei\PestE2E\Tests\Support\ReportTree;
use ValcuAndrei\PestE2E\Tests\Support\TemporaryDirectories;
use ValcuAndrei\PestE2E\Workers\Playwright\PlaywrightWorker;

/*
 * End to end through the real pieces: E2ERunner, the real Playwright CLI, the
 * package's JS harness as global setup, and unmodified native specs. A tiny
 * PHP server stands in for the application's auth endpoint and answers each
 * ticket with a session cookie naming that ticket.
 *
 * Needs node and an installed @playwright/test above the package directory;
 * skipped otherwise. No browser is launched.
 */

function storageStatePlaywrightNodeModules(): ?string
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

function storageStateFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * @param  array<string, mixed>  $params
 */
function runNativeTarget(string $project, string $runId, string $spec, array $params): JsonReportDTO
{
    $registry = new TargetRegistry;
    $registry->put(new TargetConfigDTO(name: 'frontend', dir: $project.'/e2e', env: [], params: []));

    $runner = new E2ERunner(
        registry: $registry,
        planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter),
        jsWorker: new PlaywrightWorker(new JsPackageManager),
        reportReader: new JsonReportReader(new PlaywrightParser),
        runIdGenerator: new class($runId) implements RunIdGeneratorContract
        {
            public function __construct(private readonly string $runId) {}

            public function generate(): string
            {
                return $this->runId;
            }
        },
        reportDirectoryManager: new ReportDirectoryManager,
    );

    return $runner->run('frontend', env: ['APP_URL' => $params['baseUrl']], params: $params, specPath: $spec);
}

function nativeStatus(JsonReportDTO $report, string $titleStart): ?string
{
    foreach ($report->getTests() as $test) {
        if (str_contains($test->name, $titleStart)) {
            return $test->status->value;
        }
    }

    return null;
}

beforeEach(function (): void {
    $nodeModules = storageStatePlaywrightNodeModules();
    $node = new Process(['node', '--version']);
    $node->run();

    if ($nodeModules === null || ! $node->isSuccessful()) {
        $this->markTestSkipped('Needs node and an installed @playwright/test above the package directory.');
    }

    $this->tree = ReportTree::create('pest-e2e-native-state-reports-');
    $this->project = TemporaryDirectories::create('pest-e2e-native-state-');
    mkdir($this->project.'/e2e', 0700, true);
    mkdir($this->project.'/pest-e2e', 0700, true);
    // PlaywrightWorker looks for node_modules/.bin/playwright in the target directory.
    symlink($nodeModules, $this->project.'/e2e/node_modules');

    foreach (['core.mjs', 'playwright.mjs', 'setCookieParser.mjs'] as $file) {
        copy(dirname(__DIR__, 2).'/resources/js/pest-e2e/'.$file, $this->project.'/pest-e2e/'.$file);
    }

    file_put_contents($this->project.'/e2e/playwright.config.mjs', <<<'JS'
        import { storageStatePath } from '../pest-e2e/playwright.mjs';
        export default {
            testDir: '.',
            timeout: 5000,
            globalSetup: './global-setup.mjs',
            use: { storageState: storageStatePath() },
        };
        JS);

    file_put_contents($this->project.'/e2e/global-setup.mjs', "export { globalSetup as default } from '../pest-e2e/playwright.mjs';\n");

    file_put_contents($this->project.'/e2e/state.spec.mjs', <<<'JS'
        import { test, expect } from '@playwright/test';
        import { readFileSync } from 'fs';
        import { join } from 'path';
        import { readParams } from '../pest-e2e/core.mjs';

        test('the worker receives the storage state global setup wrote for this run', async ({ storageState }) => {
            const params = await readParams();
            expect(storageState).toBe(join(process.env.PEST_E2E_RUNTIME_DIR, 'storageState.json'));
            const state = JSON.parse(readFileSync(storageState, 'utf8'));
            expect(state.cookies.map((cookie) => cookie.value)).toEqual(params.expectCookies);
        });

        test('fails natively after reading the state', async ({ storageState }) => {
            expect(JSON.parse(readFileSync(storageState, 'utf8')).cookies.length).toBe(1);
            expect('actual value').toBe('expected value');
        });
        JS);

    file_put_contents($this->project.'/auth-server.php', <<<'PHP'
        <?php
        $body = json_decode((string) file_get_contents('php://input'), true);
        header('Set-Cookie: laravel_session=session-for-'.($body['ticket'] ?? 'nobody').'; Path=/; HttpOnly');
        http_response_code(204);
        PHP);

    $this->port = storageStateFreePort();
    $this->server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $this->project.'/auth-server.php']);
    $this->server->start();
    $this->baseUrl = 'http://127.0.0.1:'.$this->port;

    $deadline = microtime(true) + 5;

    while (microtime(true) < $deadline && @fsockopen('127.0.0.1', $this->port) === false) {
        usleep(20_000);
    }

    $this->authenticated = fn (string $ticket): array => [
        'baseUrl' => $this->baseUrl,
        'auth' => ['ticket' => $ticket, 'mode' => 'session', 'guard' => 'web'],
        'expectCookies' => ['session-for-'.$ticket],
    ];
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop(1);
    }

    if (isset($this->project)) {
        TemporaryDirectories::remove($this->project);
        $this->tree->destroy();
    }
});

it('hands each authenticated run its own storage state and removes it afterwards', function (): void {
    $first = runNativeTarget($this->project, 'run-user-1', 'state.spec.mjs', ($this->authenticated)('user-1'));
    $second = runNativeTarget($this->project, 'run-user-2', 'state.spec.mjs', ($this->authenticated)('user-2'));

    // The spec itself asserts that the cookie it received belongs to this run's ticket.
    expect(nativeStatus($first, 'the worker receives'))->toBe('passed')
        ->and(nativeStatus($second, 'the worker receives'))->toBe('passed')
        ->and(is_dir($this->tree->base.'/.pest-e2e-state/frontend/run-user-1/runtime'))->toBeFalse()
        ->and(is_dir($this->tree->base.'/.pest-e2e-state/frontend/run-user-2/runtime'))->toBeFalse();
});

it('gives an unauthenticated run an empty state after an authenticated one', function (): void {
    runNativeTarget($this->project, 'run-user-1', 'state.spec.mjs', ($this->authenticated)('user-1'));
    $anonymous = runNativeTarget($this->project, 'run-anonymous', 'state.spec.mjs', ['baseUrl' => $this->baseUrl, 'expectCookies' => []]);

    expect(nativeStatus($anonymous, 'the worker receives'))->toBe('passed');
});

it('creates nothing inside the Playwright test directory', function (): void {
    $before = scandir($this->project.'/e2e');

    runNativeTarget($this->project, 'run-user-1', 'state.spec.mjs', ($this->authenticated)('user-1'));

    expect(scandir($this->project.'/e2e'))->toBe($before)
        ->and(file_exists($this->project.'/e2e/.pest-e2e'))->toBeFalse();
});

it('leaves no session cookie in the retained report tree, also after a native failure', function (): void {
    $report = runNativeTarget($this->project, 'run-user-1', 'state.spec.mjs', ($this->authenticated)('user-1'));

    $statuses = array_map(static fn ($test): string => $test->status->value, $report->getTests());
    sort($statuses);

    expect($statuses)->toBe(['failed', 'passed'])
        ->and(is_dir($this->tree->base.'/frontend/run-user-1'))->toBeTrue()
        ->and(is_dir($this->tree->base.'/.pest-e2e-state/frontend/run-user-1/runtime'))->toBeFalse()
        ->and(TemporaryDirectories::filesContaining($this->tree->base, 'session-for-'))->toBe([])
        ->and(glob($this->tree->base.'/frontend/run-user-1/storageState.json'))->toBe([]);
});
