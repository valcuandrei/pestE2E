<?php

declare(strict_types=1);

use NunoMaduro\Collision\Adapters\Phpunit\Printers\DefaultPrinter;
use NunoMaduro\Collision\Adapters\Phpunit\TestResult;
use Symfony\Component\Console\Output\BufferedOutput;
use ValcuAndrei\PestE2E\Collision\Events;
use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\Plugin;
use ValcuAndrei\PestE2E\Support\CliOptions;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

beforeEach(function (): void {
    unset(
        $_SERVER['PEST_E2E_AGENT_OUTPUT'],
        $_SERVER['PAO_FORCE'],
        $_SERVER['PEST_E2E_AGENT_OUTPUT_DISABLE'],
        $_SERVER['CURSOR_AGENT'],
        $_SERVER['COLLISION_PRINTER_COMPACT'],
        $_SERVER['TEST_TOKEN'],
        $_ENV['TEST_TOKEN'],
        $_ENV['COLLISION_PRINTER_COMPACT'],
    );
    putenv('PEST_E2E_AGENT_OUTPUT');
    putenv('PAO_FORCE');
    putenv('PEST_E2E_AGENT_OUTPUT_DISABLE');
    putenv('CURSOR_AGENT');
    putenv('COLLISION_PRINTER_COMPACT');
    putenv('TEST_TOKEN');

    $_SERVER['PEST_E2E_AGENT_OUTPUT_DISABLE'] = '1';

    if (class_exists(DefaultPrinter::class)) {
        DefaultPrinter::compact(false);
    }

    CliOptions::$browse = false;
    CliOptions::$debug = false;
    CliOptions::$compact = false;
    CliOptions::$parallel = false;
    CliOptions::$agentOutput = false;

    app(E2EOutputStore::class)->flush();
    app(E2EOutputStore::class)->flushPerTestEntries();
});

it('prints inline e2e output after the test line and does not repeat at the end', function () {
    $store = app(E2EOutputStore::class);

    $formatter = new E2EOutputFormatter;
    $parentTestName = 'prints inline output';
    $tests = [JsonReportTestDTO::fakePassed()->withName('js passes')];

    $lines = $formatter->buildRunLines(
        target: 'frontend',
        runId: 'run-123',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
        tests: $tests,
        parentTestName: $parentTestName,
        extraLines: [],
    );

    $entry = new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-123',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
        lines: $lines,
    );

    $testId = 'test-id-123';
    $store->putForTest($testId, $entry);

    $output = new BufferedOutput;
    Events::setOutput($output);

    $output->writeln('✓ '.$parentTestName);

    Events::afterTestMethodDescription(makeTestResult($testId));

    $rendered = $output->fetch();
    $plainText = normalizeFormattedOutput($rendered);
    $plainText = str_replace("\r\n", "\n", $plainText);
    $branchPrefix = E2EOutputFormatter::BASE_INDENT.E2EOutputFormatter::BRANCH_PREFIX;
    $childIndent = E2EOutputFormatter::BASE_INDENT.E2EOutputFormatter::CHILD_INDENT;

    expect(substr_count($plainText, $parentTestName))->toBe(1)
        ->and($plainText)->toContain('✓ '.$parentTestName."\n".$branchPrefix.'E2E › frontend (runId run-123)')
        ->and($plainText)->toContain($childIndent.'✓ js passes');

    expect($store->getForTest($testId))->toBe([]);

    $pluginOutput = new BufferedOutput;
    $plugin = new Plugin($pluginOutput);
    $plugin->addOutput(0);

    expect($pluginOutput->fetch())->toBe('');
});

it('defers inline e2e output to the plugin in agent output mode', function (): void {
    unset($_SERVER['PEST_E2E_AGENT_OUTPUT_DISABLE']);
    putenv('PEST_E2E_AGENT_OUTPUT_DISABLE');
    $_SERVER['PEST_E2E_AGENT_OUTPUT'] = '1';

    $store = app(E2EOutputStore::class);

    $entry = new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-agent-inline',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
        lines: ['inline parent', 'passed inline e2e line'],
    );

    $testId = 'test-id-agent-inline';
    $store->putForTest($testId, $entry);

    $output = new BufferedOutput;
    Events::setOutput($output);
    $output->writeln('✓ inline parent');

    Events::afterTestMethodDescription(makeTestResult($testId));

    expect($output->fetch())->toBe('✓ inline parent'.PHP_EOL)
        ->and($store->getForTest($testId))->toHaveCount(1);
});

it('suppresses passed inline e2e output in compact mode', function (): void {
    $store = app(E2EOutputStore::class);
    CliOptions::fromArguments(['--compact']);

    $entry = new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-compact-inline',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
        lines: ['inline parent', 'passed inline e2e line'],
    );

    $testId = 'test-id-compact-pass';
    $store->putForTest($testId, $entry);

    $output = new BufferedOutput;
    Events::setOutput($output);
    $output->writeln('✓ inline parent');

    Events::afterTestMethodDescription(makeTestResult($testId));

    expect($output->fetch())->toBe('✓ inline parent'.PHP_EOL)
        ->and($store->getForTest($testId))->toBe([]);
});

it('keeps failed inline e2e output in parallel mode', function (): void {
    $store = app(E2EOutputStore::class);
    CliOptions::fromArguments(['--parallel']);

    $entry = new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-parallel-inline-fail',
        ok: false,
        durationSeconds: 0.12,
        stats: null,
        lines: ['inline parent', 'failed inline e2e line'],
    );

    $testId = 'test-id-parallel-fail';
    $store->putForTest($testId, $entry);

    $output = new BufferedOutput;
    Events::setOutput($output);
    $output->writeln('✗ inline parent');

    Events::afterTestMethodDescription(makeTestResult($testId));

    expect($output->fetch())->toContain('failed inline e2e line')
        ->and($store->getForTest($testId))->toBe([]);
});

function makeTestResult(string $testId): TestResult
{
    $reflection = new ReflectionClass(TestResult::class);
    $result = $reflection->newInstanceWithoutConstructor();
    $result->id = $testId;

    return $result;
}

if (! function_exists('normalizeFormattedOutput')) {
    function normalizeFormattedOutput(string $text): string
    {
        $withoutTags = strip_tags($text);

        return (string) preg_replace('/\e\[[0-9;]*m/', '', $withoutTags);
    }
}
