<?php

declare(strict_types=1);

use NunoMaduro\Collision\Adapters\Phpunit\TestResult;
use Pest\Collision\Events;
use Symfony\Component\Console\Output\BufferedOutput;
use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\Plugin;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

it('prints inline e2e output after the test line and does not repeat at the end', function () {
    $store = app(E2EOutputStore::class);
    $store->flush();
    $store->flushPerTestEntries();

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

function makeTestResult(string $testId): TestResult
{
    $reflection = new \ReflectionClass(TestResult::class);
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
