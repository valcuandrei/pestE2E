<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\ReportPruneOptionsDTO;
use ValcuAndrei\PestE2E\Support\ReportPruner;
use ValcuAndrei\PestE2E\Support\ReportRunRegistry;
use ValcuAndrei\PestE2E\Support\SafeDirectoryRemover;
use ValcuAndrei\PestE2E\Tests\Support\ReportTree;
use ValcuAndrei\PestE2E\Tests\Support\TemporaryDirectories;

/*
 * Nothing outside the report tree pest-e2e owns may be deleted, however the
 * tree is arranged. These tests plant an "outside" tree, point at it from
 * every place a deletion path could be led astray, run the most aggressive
 * operations, and require the outside tree to be byte-for-byte unchanged.
 */

/**
 * Every path under a directory with its type and, for files, a content hash.
 *
 * @return array<string, string>
 */
function treeSnapshot(string $directory): array
{
    $snapshot = [];

    $walk = static function (string $current) use (&$walk, &$snapshot, $directory): void {
        foreach (scandir($current) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $current.'/'.$entry;
            $relative = substr($path, strlen($directory) + 1);

            if (is_link($path)) {
                $snapshot[$relative] = 'link:'.readlink($path);
            } elseif (is_dir($path)) {
                $snapshot[$relative] = 'dir';
                $walk($path);
            } else {
                $snapshot[$relative] = 'file:'.sha1_file($path);
            }
        }
    };

    $walk($directory);
    ksort($snapshot);

    return $snapshot;
}

beforeEach(function (): void {
    $this->tree = ReportTree::create('pest-e2e-boundary-reports-');
    $this->outside = TemporaryDirectories::create('pest-e2e-boundary-outside-');

    // Shaped like things pest-e2e would happily delete if it believed it owned them.
    mkdir($this->outside.'/frontend/0123456789abcdef', 0700, true);
    file_put_contents($this->outside.'/frontend/0123456789abcdef/.last-run.json', '{"status":"passed"}');
    file_put_contents($this->outside.'/frontend/0123456789abcdef/.pest-e2e-run', '{}');
    mkdir($this->outside.'/runtime', 0700);
    file_put_contents($this->outside.'/runtime/storageState.json', '{"cookies":[]}');
    file_put_contents($this->outside.'/run.json', '{"state":"running"}');
    file_put_contents($this->outside.'/precious.txt', 'must survive');

    $longAgo = time() - 400 * 86400;

    foreach ([$this->outside.'/frontend/0123456789abcdef/.last-run.json', $this->outside.'/frontend/0123456789abcdef/.pest-e2e-run', $this->outside.'/frontend/0123456789abcdef', $this->outside.'/frontend', $this->outside.'/run.json'] as $path) {
        touch($path, $longAgo);
    }

    $this->outsideBefore = treeSnapshot($this->outside);
    $this->everything = new ReportPruneOptionsDTO(keepRuns: 1, keepDays: 1, graceSeconds: 0, all: true, deleteLegacy: true);
});

afterEach(function (): void {
    $this->tree->destroy();
    TemporaryDirectories::remove($this->outside);
});

it('leaves everything outside the report tree untouched, whatever links point there', function (): void {
    $base = $this->tree->base;
    $longAgo = time() - 400 * 86400;

    // Owned, old runs that really should be deleted — so the test proves deletion happened.
    $ownedOld = $this->tree->finished('owned-old', 30 * 86400);
    $ownedWithLink = $this->tree->finished('owned-with-link', 30 * 86400);
    symlink($this->outside, $ownedWithLink.'/link-to-outside-dir');
    symlink($this->outside.'/precious.txt', $ownedWithLink.'/link-to-outside-file');

    // A whole target that is a link to the outside tree.
    symlink($this->outside.'/frontend', $base.'/linked-target');

    // A run directory that is a link to an outside "run".
    symlink($this->outside.'/frontend/0123456789abcdef', $base.'/frontend/linked-run');

    // An unmarked legacy directory containing a link out.
    $legacyWithLink = $this->tree->unmarked('fedcba9876543210', 400 * 86400);
    symlink($this->outside, $legacyWithLink.'/link');
    touch($legacyWithLink, $longAgo);

    // An owned run whose report directory was swapped for a link after it was registered.
    $swapped = $this->tree->finished('swapped', 30 * 86400);
    TemporaryDirectories::removeInside($swapped, $base);
    symlink($this->outside.'/frontend/0123456789abcdef', $swapped);

    // An abandoned run whose runtime directory was swapped for a link.
    $abandoned = $this->tree->abandoned('abandoned', 30 * 86400);
    TemporaryDirectories::removeInside($abandoned->runtimeDirectory, $base);
    symlink($this->outside.'/runtime', $abandoned->runtimeDirectory);

    // Registry entries that are links: a target, a run, and a record file.
    symlink($this->outside, ReportRunRegistry::stateRoot($base).'/linked-state-target');
    symlink($this->outside, ReportRunRegistry::stateRoot($base).'/frontend/linked-state-run');
    mkdir(ReportRunRegistry::stateRoot($base).'/frontend/linked-record', 0700);
    symlink($this->outside.'/run.json', ReportRunRegistry::stateRoot($base).'/frontend/linked-record/run.json');

    $result = (new ReportPruner)->prune($base, $this->everything);

    expect(treeSnapshot($this->outside))->toBe($this->outsideBefore)
        // Not vacuous: the owned run was deleted, the swapped one was refused.
        ->and(is_dir($ownedOld))->toBeFalse()
        ->and(is_dir($ownedWithLink))->toBeFalse()
        ->and($result->pruned)->toContain($ownedOld)
        ->and($result->failed)->toContain($swapped)
        ->and(is_link($swapped))->toBeTrue()
        ->and(is_dir($legacyWithLink))->toBeTrue()
        ->and(is_link($base.'/linked-target'))->toBeTrue()
        ->and(is_link($base.'/frontend/linked-run'))->toBeTrue();
});

it('leaves the outside tree untouched when a run with a hijacked runtime directory finishes', function (): void {
    $run = $this->tree->manager()->begin('frontend', 'hijacked');
    rmdir($run->runtimeDirectory);
    symlink($this->outside.'/runtime', $run->runtimeDirectory);

    $this->tree->manager()->finish($run, 'passed');

    expect(treeSnapshot($this->outside))->toBe($this->outsideBefore);
});

it('leaves the outside tree untouched when the whole report directory is a link to it', function (): void {
    $link = $this->tree->base.'/link-to-outside-as-base';
    symlink($this->outside, $link);

    $result = (new ReportPruner)->prune($link, $this->everything);

    expect($result->skippedReason)->toContain('symbolic link')
        ->and(treeSnapshot($this->outside))->toBe($this->outsideBefore);
});

it('would have deleted the same content had it been inside an owned tree', function (): void {
    // Control for the tests above: identical legacy content, but genuinely inside the report tree.
    $legacy = $this->tree->unmarked('0123456789abcdef', 400 * 86400);
    $marked = $this->tree->legacyMarked('aaaaaaaaaaaaaaaa', 400 * 86400);

    (new ReportPruner)->prune($this->tree->base, $this->everything);

    expect(is_dir($legacy))->toBeFalse()
        ->and(is_dir($marked))->toBeFalse();
});

it('refuses to remove anything that is not strictly inside the given root', function (): void {
    $root = $this->tree->base;
    mkdir($root.'/inside/child', 0700, true);
    $sibling = $root.'-sibling';
    mkdir($sibling, 0700);
    file_put_contents($sibling.'/keep.txt', 'keep');
    symlink($this->outside, $root.'/link');

    expect(SafeDirectoryRemover::removeTreeInside($root, $root))->toBeFalse()
        ->and(SafeDirectoryRemover::removeTreeInside($this->outside, $root))->toBeFalse()
        ->and(SafeDirectoryRemover::removeTreeInside($sibling, $root))->toBeFalse()
        ->and(SafeDirectoryRemover::removeTreeInside($root.'/link', $root))->toBeFalse()
        ->and(SafeDirectoryRemover::removeTreeInside($root.'/inside/../../'.basename($this->outside), $root))->toBeFalse()
        ->and(SafeDirectoryRemover::removeTreeInside(dirname($root), $root))->toBeFalse()
        ->and(SafeDirectoryRemover::removeTreeInside('/', $root))->toBeFalse()
        ->and(is_dir($root))->toBeTrue()
        ->and(is_file($sibling.'/keep.txt'))->toBeTrue()
        ->and(treeSnapshot($this->outside))->toBe($this->outsideBefore);

    expect(SafeDirectoryRemover::removeTreeInside($root.'/inside', $root))->toBeTrue()
        ->and(is_dir($root.'/inside'))->toBeFalse()
        ->and(SafeDirectoryRemover::removeTreeInside($root.'/never-existed', $root))->toBeTrue();

    unlink($sibling.'/keep.txt');
    rmdir($sibling);
});
