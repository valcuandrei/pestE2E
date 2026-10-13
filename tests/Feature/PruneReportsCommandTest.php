<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use ValcuAndrei\PestE2E\Tests\Support\ReportTree;
use ValcuAndrei\PestE2E\Tests\Support\TemporaryDirectories;

const COMMAND_DAY = 86400;

beforeEach(function (): void {
    $this->tree = ReportTree::create('pest-e2e-command-test-');
    config()->set('pest-e2e.reports.prune', ['enabled' => true, 'keep_runs' => 500, 'keep_days' => 7, 'grace_minutes' => 60, 'interval_minutes' => 15]);
});

afterEach(function (): void {
    $this->tree->destroy();
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function pruneReports(array $arguments = []): array
{
    $exitCode = Artisan::call('pest-e2e:prune-reports', $arguments);

    return [$exitCode, Artisan::output()];
}

it('applies the configured retention when called without options', function (): void {
    $old = $this->tree->finished('old', 8 * COMMAND_DAY);
    $recent = $this->tree->finished('recent', 2 * COMMAND_DAY);

    [$exitCode, $output] = pruneReports();

    expect($exitCode)->toBe(0)
        ->and(is_dir($old))->toBeFalse()
        ->and(is_dir($recent))->toBeTrue()
        ->and($output)->toContain('Report directory: '.$this->tree->base)
        ->and($output)->toContain('Deleted 1 run(s).');
});

it('works even when automatic pruning is disabled in config', function (): void {
    config()->set('pest-e2e.reports.prune.enabled', false);
    $old = $this->tree->finished('old', 8 * COMMAND_DAY);

    pruneReports();

    expect(is_dir($old))->toBeFalse();
});

it('shows what a dry run would delete and deletes nothing', function (): void {
    $old = $this->tree->finished('old', 8 * COMMAND_DAY);

    [$exitCode, $output] = pruneReports(['--dry-run' => true]);

    expect($exitCode)->toBe(0)
        ->and(is_dir($old))->toBeTrue()
        ->and($output)->toContain('Dry run: nothing will be deleted.')
        ->and($output)->toContain('Would delete 1 run(s).')
        ->and($output)->toContain($old);
});

it('prunes by age with --unit=days', function (): void {
    $threeDays = $this->tree->finished('three-days', 3 * COMMAND_DAY);
    $oneDay = $this->tree->finished('one-day', COMMAND_DAY);

    pruneReports(['--unit' => 'days', '--value' => '2']);

    expect(is_dir($threeDays))->toBeFalse()
        ->and(is_dir($oneDay))->toBeTrue();
});

it('keeps the newest runs with --unit=items, whatever their age', function (): void {
    $newest = $this->tree->finished('newest', 20 * COMMAND_DAY);
    $older = $this->tree->finished('older', 21 * COMMAND_DAY);
    $oldest = $this->tree->finished('oldest', 22 * COMMAND_DAY);

    pruneReports(['--unit' => 'items', '--value' => '2']);

    // An explicit rule replaces both configured rules: 20 days old, yet kept.
    expect(is_dir($newest))->toBeTrue()
        ->and(is_dir($older))->toBeTrue()
        ->and(is_dir($oldest))->toBeFalse();
});

it('rejects an unknown unit', function (): void {
    $old = $this->tree->finished('old', 8 * COMMAND_DAY);

    [$exitCode, $output] = pruneReports(['--unit' => 'weeks', '--value' => '1']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Valid units are: days, items.')
        ->and(is_dir($old))->toBeTrue();
});

it('rejects a unit without a usable value', function (array $arguments): void {
    $old = $this->tree->finished('old', 8 * COMMAND_DAY);

    [$exitCode] = pruneReports($arguments);

    expect($exitCode)->toBe(1)
        ->and(is_dir($old))->toBeTrue();
})->with([
    'no value' => [['--unit' => 'days']],
    'negative value' => [['--unit' => 'days', '--value' => '-1']],
    'not a number' => [['--unit' => 'items', '--value' => 'many']],
    'value without unit' => [['--value' => '3']],
    'zero would keep nothing' => [['--unit' => 'items', '--value' => '0']],
]);

it('deletes every inactive owned run with --all, including recent ones', function (): void {
    $recent = $this->tree->finished('recent', 60);
    $old = $this->tree->finished('old', 8 * COMMAND_DAY);

    [, $output] = pruneReports(['--all' => true]);

    expect(is_dir($recent))->toBeFalse()
        ->and(is_dir($old))->toBeFalse()
        ->and($output)->toContain('Deleted 2 run(s).');
});

it('never deletes an active run or an unmarked directory with --all', function (): void {
    $active = $this->tree->active('in-progress', 30 * COMMAND_DAY);
    $unmarked = $this->tree->unmarked('0123456789abcdef', 90 * COMMAND_DAY);
    $manual = $this->tree->unmarked('manual-notes', 90 * COMMAND_DAY, playwrightOutput: false);

    [, $output] = pruneReports(['--all' => true]);

    expect(is_dir($active->reportDirectory))->toBeTrue()
        ->and(is_dir($unmarked))->toBeTrue()
        ->and(is_dir($manual))->toBeTrue()
        ->and($output)->toContain('Skipped, active: 1')
        ->and($output)->toContain('Unmarked directories (not owned): 2')
        ->and($output)->toContain('Never deleted automatically. Use --legacy to inspect them.');

    $active->releaseLock();
});

it('inspects unmarked legacy directories without deleting them', function (): void {
    $eligible = $this->tree->unmarked('0123456789abcdef', 90 * COMMAND_DAY);
    $refused = $this->tree->unmarked('manual-notes', 90 * COMMAND_DAY, playwrightOutput: false);
    file_put_contents($refused.'/keep.txt', 'keep');

    [$exitCode, $output] = pruneReports(['--legacy' => true]);

    expect($exitCode)->toBe(0)
        ->and(is_dir($eligible))->toBeTrue()
        ->and(is_dir($refused))->toBeTrue()
        ->and($output)->toContain('Could delete with --delete-unmarked-legacy-runs: 1 unmarked legacy run(s).')
        ->and($output)->toContain('Refused: 1')
        ->and($output)->toContain('manual-notes — does not look like a Playwright output directory');
});

it('deletes validated unmarked legacy directories only with the explicit flag', function (): void {
    $eligible = $this->tree->unmarked('0123456789abcdef', 90 * COMMAND_DAY);
    $refused = $this->tree->unmarked('manual-notes', 90 * COMMAND_DAY, playwrightOutput: false);
    file_put_contents($refused.'/keep.txt', 'keep');
    touch($refused, time() - 90 * COMMAND_DAY);

    [, $dryRunOutput] = pruneReports(['--delete-unmarked-legacy-runs' => true, '--dry-run' => true]);

    expect(is_dir($eligible))->toBeTrue()
        ->and($dryRunOutput)->toContain('Would delete 1 unmarked legacy run(s).');

    [, $output] = pruneReports(['--delete-unmarked-legacy-runs' => true]);

    expect(is_dir($eligible))->toBeFalse()
        ->and(is_file($refused.'/keep.txt'))->toBeTrue()
        ->and($output)->toContain('Deleted 1 unmarked legacy run(s).');
});

it('reports when there is no report directory yet', function (): void {
    config()->set('pest-e2e.reports.base_dir', $this->tree->base.'/not-created');

    [$exitCode, $output] = pruneReports();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Nothing to do: the report directory does not exist.');
});

it('only works inside the configured report directory', function (): void {
    $outside = TemporaryDirectories::create('pest-e2e-command-outside-');
    mkdir($outside.'/frontend/0123456789abcdef', 0700, true);
    file_put_contents($outside.'/frontend/0123456789abcdef/.last-run.json', '{}');
    touch($outside.'/frontend/0123456789abcdef', time() - 90 * COMMAND_DAY);
    symlink($outside.'/frontend', $this->tree->base.'/linked');

    pruneReports(['--all' => true, '--delete-unmarked-legacy-runs' => true]);

    expect(is_dir($outside.'/frontend/0123456789abcdef'))->toBeTrue();

    TemporaryDirectories::remove($outside);
});
