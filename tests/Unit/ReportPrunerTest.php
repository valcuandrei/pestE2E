<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\ReportPruneOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ReportPruneResultDTO;
use ValcuAndrei\PestE2E\Support\ReportPruner;
use ValcuAndrei\PestE2E\Support\ReportRunRegistry;
use ValcuAndrei\PestE2E\Tests\Support\ReportTree;
use ValcuAndrei\PestE2E\Tests\Support\TemporaryDirectories;

const PRUNE_HOUR = 3600;
const PRUNE_DAY = 86400;

beforeEach(function (): void {
    $this->tree = ReportTree::create();
    $this->outside = TemporaryDirectories::create('pest-e2e-prune-outside-');
    file_put_contents($this->outside.'/precious.txt', 'must survive');
});

afterEach(function (): void {
    $this->tree->destroy();
    TemporaryDirectories::remove($this->outside);
});

function pruneTree(ReportTree $tree, ReportPruneOptionsDTO $options): ReportPruneResultDTO
{
    return (new ReportPruner)->prune($tree->base, $options);
}

it('deletes runs older than keep_days and keeps newer ones', function (): void {
    $old = $this->tree->finished('old', 8 * PRUNE_DAY);
    $recent = $this->tree->finished('recent', 2 * PRUNE_DAY);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 0, keepDays: 7));

    expect(is_dir($old))->toBeFalse()
        ->and(is_dir($recent))->toBeTrue()
        ->and($result->pruned)->toBe([$old])
        ->and($result->owned)->toBe(2);
});

it('removes the registry record together with the report directory', function (): void {
    $this->tree->finished('old', 8 * PRUNE_DAY);

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    expect(is_dir(ReportRunRegistry::stateDirectory($this->tree->base, 'frontend', 'old')))->toBeFalse();
});

it('keeps only the newest keep_runs runs', function (): void {
    $newest = $this->tree->finished('newest', 2 * PRUNE_HOUR);
    $middle = $this->tree->finished('middle', 3 * PRUNE_HOUR);
    $oldest = $this->tree->finished('oldest', 4 * PRUNE_HOUR);

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 2, keepDays: 0));

    expect(is_dir($newest))->toBeTrue()
        ->and(is_dir($middle))->toBeTrue()
        ->and(is_dir($oldest))->toBeFalse();
});

it('deletes a run older than keep_days even when it is among the newest keep_runs', function (): void {
    $recent = $this->tree->finished('recent', 2 * PRUNE_HOUR);
    $old = $this->tree->finished('old', 30 * PRUNE_DAY);

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 500, keepDays: 7));

    // Either rule alone is enough: two runs is well inside keep_runs, age still applies.
    expect(is_dir($recent))->toBeTrue()
        ->and(is_dir($old))->toBeFalse();
});

it('turns a rule off when it is set to zero', function (): void {
    $ancient = $this->tree->finished('ancient', 400 * PRUNE_DAY);
    $older = $this->tree->finished('older', 401 * PRUNE_DAY);

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 0, keepDays: 0));

    expect(is_dir($ancient))->toBeTrue()
        ->and(is_dir($older))->toBeTrue();
});

it('never deletes a run inside the grace period, even beyond keep_runs', function (): void {
    $justFinished = $this->tree->finished('just-finished', 10 * 60);
    $alsoRecent = $this->tree->finished('also-recent', 50 * 60);
    $pastGrace = $this->tree->finished('past-grace', 70 * 60);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 1, keepDays: 0, graceSeconds: PRUNE_HOUR));

    // A failure from a few minutes ago keeps its trace whatever the count rule says.
    expect(is_dir($justFinished))->toBeTrue()
        ->and(is_dir($alsoRecent))->toBeTrue()
        ->and(is_dir($pastGrace))->toBeFalse()
        ->and($result->inGrace)->toBe(1);
});

it('never deletes an active run, however old its record is', function (): void {
    $active = $this->tree->active('long-running', 30 * PRUNE_DAY);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 0, keepDays: 1, graceSeconds: 0));

    expect(is_dir($active->reportDirectory))->toBeTrue()
        ->and(is_file($active->runtimeDirectory.'/storageState.json'))->toBeTrue()
        ->and($result->active)->toBe(1)
        ->and($result->pruned)->toBe([]);

    $active->releaseLock();
});

it('never deletes an active run even when asked to delete everything', function (): void {
    $active = $this->tree->active('long-running', 30 * PRUNE_DAY);
    $finished = $this->tree->finished('finished', 5 * 60);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(all: true));

    expect(is_dir($active->reportDirectory))->toBeTrue()
        ->and(is_dir($finished))->toBeFalse()
        ->and($result->active)->toBe(1);

    $active->releaseLock();
});

it('still owns a run after Playwright has emptied its output directory', function (): void {
    $run = $this->tree->manager()->begin('frontend', 'wiped');

    // The in-directory marker is gone the moment Playwright starts.
    $this->tree->playwrightWritesOutput($run->reportDirectory);
    expect(is_file($run->reportDirectory.'/.pest-e2e-run'))->toBeFalse();

    $this->tree->manager()->finish($run, 'failed');
    touch($this->tree->record($run), time() - 8 * PRUNE_DAY);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    expect($result->owned)->toBe(1)
        ->and($result->legacyFound)->toBe(0)
        ->and(is_dir($run->reportDirectory))->toBeFalse();
});

it('prunes an abandoned run like a finished one once its lock is gone', function (): void {
    $abandoned = $this->tree->abandoned('crashed', 8 * PRUNE_DAY);

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    expect(is_dir($abandoned->reportDirectory))->toBeFalse()
        ->and(is_dir($abandoned->stateDirectory))->toBeFalse();
});

it('removes the runtime files of an abandoned run long before its report expires', function (): void {
    $abandoned = $this->tree->abandoned('crashed', 5 * 60);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    // Session cookies go; the report stays for diagnosis.
    expect(is_dir($abandoned->runtimeDirectory))->toBeFalse()
        ->and(is_dir($abandoned->reportDirectory))->toBeTrue()
        ->and($result->staleRuntimeRemoved)->toBe(1);
});

it('removes stale runtime files even when report retention is switched off', function (): void {
    $abandoned = $this->tree->abandoned('crashed', 5 * 60);
    $old = $this->tree->finished('old', 30 * PRUNE_DAY);

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7, retention: false));

    expect(is_dir($abandoned->runtimeDirectory))->toBeFalse()
        ->and(is_dir($old))->toBeTrue();
});

it('leaves the runtime files of an active run alone', function (): void {
    $active = $this->tree->active('in-progress', 5 * 60);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO);

    expect(is_file($active->runtimeDirectory.'/storageState.json'))->toBeTrue()
        ->and($result->staleRuntimeRemoved)->toBe(0);

    $active->releaseLock();
});

it('does not treat a run allocated seconds ago as abandoned', function (): void {
    $starting = $this->tree->abandoned('starting', 5);

    pruneTree($this->tree, new ReportPruneOptionsDTO);

    expect(is_file($starting->runtimeDirectory.'/storageState.json'))->toBeTrue();
});

it('never deletes the protected current run', function (): void {
    $current = $this->tree->finished('current', 30 * PRUNE_DAY);
    $other = $this->tree->finished('other', 30 * PRUNE_DAY);

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7, protectedReportDirectories: [$current]));

    expect(is_dir($current))->toBeTrue()
        ->and(is_dir($other))->toBeFalse();
});

it('counts unmarked directories but never deletes them by default', function (): void {
    $unmarked = $this->tree->unmarked('0123456789abcdef', 90 * PRUNE_DAY);
    $manual = $this->tree->unmarked('notes-from-a-human', 90 * PRUNE_DAY, playwrightOutput: false);
    file_put_contents($manual.'/keep.txt', 'keep');

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 1, keepDays: 1, all: true));

    expect(is_dir($unmarked))->toBeTrue()
        ->and(is_file($manual.'/keep.txt'))->toBeTrue()
        ->and($result->legacyFound)->toBe(2)
        ->and($result->legacyEligible)->toBe([])
        ->and($result->pruned)->toBe([]);
});

it('reports which unmarked directories could be removed without deleting them', function (): void {
    $eligible = $this->tree->unmarked('0123456789abcdef', 90 * PRUNE_DAY);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(inspectLegacy: true));

    expect($result->legacyEligible)->toBe([$eligible])
        ->and(is_dir($eligible))->toBeTrue();
});

it('deletes validated unmarked directories only when legacy deletion is requested', function (): void {
    $eligible = $this->tree->unmarked('0123456789abcdef', 90 * PRUNE_DAY);
    mkdir($eligible.'/spec-chromium');
    file_put_contents($eligible.'/spec-chromium/trace.zip', 'trace');
    touch($eligible.'/spec-chromium', time() - 90 * PRUNE_DAY);
    touch($eligible, time() - 90 * PRUNE_DAY);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(deleteLegacy: true));

    expect(is_dir($eligible))->toBeFalse()
        ->and($result->legacyEligible)->toBe([$eligible]);
});

it('refuses unmarked directories that do not look like old runs', function (): void {
    $notPlaywright = $this->tree->unmarked('manual-notes', 90 * PRUNE_DAY, playwrightOutput: false);
    file_put_contents($notPlaywright.'/keep.txt', 'keep');
    touch($notPlaywright.'/keep.txt', time() - 90 * PRUNE_DAY);

    $withSymlink = $this->tree->unmarked('has-a-link', 90 * PRUNE_DAY);
    symlink($this->outside, $withSymlink.'/link');

    $recent = $this->tree->unmarked('modified-just-now', 60);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(graceSeconds: PRUNE_HOUR, deleteLegacy: true));

    expect(is_file($notPlaywright.'/keep.txt'))->toBeTrue()
        ->and(is_dir($withSymlink))->toBeTrue()
        ->and(is_dir($recent))->toBeTrue()
        ->and(is_file($this->outside.'/precious.txt'))->toBeTrue()
        ->and($result->legacyEligible)->toBe([])
        ->and($result->legacyRefused[$notPlaywright])->toContain('does not look like a Playwright output directory')
        ->and($result->legacyRefused[$withSymlink])->toContain('symbolic link')
        ->and($result->legacyRefused[$recent])->toContain('grace period');
});

it('still prunes run directories that carry the marker of earlier versions', function (): void {
    $old = $this->tree->legacyMarked('old-layout', 8 * PRUNE_DAY);
    $recent = $this->tree->legacyMarked('recent-layout', PRUNE_DAY);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    expect(is_dir($old))->toBeFalse()
        ->and(is_dir($recent))->toBeTrue()
        ->and($result->owned)->toBe(2);
});

it('applies the grace period to marker-only runs even when asked to delete everything', function (): void {
    $maybeRunning = $this->tree->legacyMarked('no-lock-to-check', 10 * 60);

    pruneTree($this->tree, new ReportPruneOptionsDTO(graceSeconds: PRUNE_HOUR, all: true));

    expect(is_dir($maybeRunning))->toBeTrue();
});

it('removes a symbolic link inside a run without touching what it points at', function (): void {
    $old = $this->tree->finished('old', 8 * PRUNE_DAY);
    symlink($this->outside, $old.'/link-to-outside');

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    expect(is_dir($old))->toBeFalse()
        ->and(is_file($this->outside.'/precious.txt'))->toBeTrue();
});

it('does not follow a report directory that has been replaced by a symbolic link', function (): void {
    $old = $this->tree->finished('old', 8 * PRUNE_DAY);
    TemporaryDirectories::removeInside($old, $this->tree->base);
    symlink($this->outside, $old);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    expect(is_file($this->outside.'/precious.txt'))->toBeTrue()
        ->and($result->pruned)->toBe([])
        ->and($result->failed)->toBe([$old]);
});

it('ignores a target directory that is a symbolic link', function (): void {
    mkdir($this->outside.'/0123456789abcdef');
    file_put_contents($this->outside.'/0123456789abcdef/.last-run.json', '{}');
    touch($this->outside.'/0123456789abcdef', time() - 90 * PRUNE_DAY);
    symlink($this->outside, $this->tree->base.'/linked-target');

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(all: true, deleteLegacy: true));

    expect(is_dir($this->outside.'/0123456789abcdef'))->toBeTrue()
        ->and($result->legacyFound)->toBe(0);
});

it('refuses to work in a report directory that is itself a symbolic link', function (): void {
    $this->tree->finished('old', 8 * PRUNE_DAY);
    $link = $this->outside.'/link-to-reports';
    symlink($this->tree->base, $link);

    $result = (new ReportPruner)->prune($link, new ReportPruneOptionsDTO(keepDays: 7));

    expect($result->skippedReason)->toContain('symbolic link')
        ->and(is_dir($this->tree->base.'/frontend/old'))->toBeTrue();
});

it('does nothing when the report directory does not exist', function (): void {
    $result = (new ReportPruner)->prune($this->tree->base.'/missing', new ReportPruneOptionsDTO);

    expect($result->skippedReason)->toContain('does not exist');
});

it('lists what it would delete in a dry run and deletes nothing', function (): void {
    $old = $this->tree->finished('old', 8 * PRUNE_DAY);
    $abandoned = $this->tree->abandoned('crashed', 5 * 60);
    $legacy = $this->tree->unmarked('0123456789abcdef', 90 * PRUNE_DAY);

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7, dryRun: true, deleteLegacy: true));

    expect($result->pruned)->toBe([$old])
        ->and($result->legacyEligible)->toBe([$legacy])
        ->and($result->staleRuntimeRemoved)->toBe(1)
        ->and(is_dir($old))->toBeTrue()
        ->and(is_dir($legacy))->toBeTrue()
        ->and(is_dir($abandoned->runtimeDirectory))->toBeTrue();
});

it('stops at its deletion limit and says there is more to do', function (): void {
    foreach (range(1, 12) as $i) {
        $this->tree->finished('old-'.$i, (8 * PRUNE_DAY) + $i);
    }

    $result = pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7, maxDeletions: 5));

    expect($result->pruned)->toHaveCount(5)
        ->and($result->truncated)->toBeTrue()
        ->and(glob($this->tree->base.'/frontend/*'))->toHaveCount(7);

    $second = pruneTree($this->tree, new ReportPruneOptionsDTO(keepDays: 7));

    expect($second->pruned)->toHaveCount(7)
        ->and($second->truncated)->toBeFalse();
});

it('keeps runs of different targets apart but counts them together', function (): void {
    $frontendOld = $this->tree->finished('old', 3 * PRUNE_HOUR, 'frontend');
    $adminNew = $this->tree->finished('new', 2 * PRUNE_HOUR, 'admin');

    pruneTree($this->tree, new ReportPruneOptionsDTO(keepRuns: 1, keepDays: 0));

    expect(is_dir($adminNew))->toBeTrue()
        ->and(is_dir($frontendOld))->toBeFalse();
});
