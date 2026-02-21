<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use ValcuAndrei\PestE2E\Actions\ReportsPrunerAction;

it('prunes in items mode keeping only the newest directories', function () {
    $tempBase = sys_get_temp_dir().'/pest-e2e-pruner-'.uniqid();
    File::ensureDirectoryExists($tempBase);

    $now = time();
    $dirs = ['run1', 'run2', 'run3', 'run4', 'run5'];
    foreach ($dirs as $i => $name) {
        $path = $tempBase.'/'.$name;
        File::ensureDirectoryExists($path);
        touch($path, $now - (10 - $i) * 86400);
    }

    try {
        $action = new ReportsPrunerAction;
        $deleted = $action->handle($tempBase, 'items', 2, false, false);

        expect($deleted)->toBe(3)
            ->and(collect(File::directories($tempBase))->map(fn ($p) => basename($p))->sort()->values()->all())
            ->toBe(['run4', 'run5']);
    } finally {
        File::deleteDirectory($tempBase);
    }
});

it('prunes in days mode deleting only directories older than threshold', function () {
    $tempBase = sys_get_temp_dir().'/pest-e2e-pruner-'.uniqid();
    File::ensureDirectoryExists($tempBase);

    $now = time();
    $old10 = $now - 10 * 86400;
    $old8 = $now - 8 * 86400;
    $old6 = $now - 6 * 86400;
    $old4 = $now - 4 * 86400;
    $old2 = $now - 2 * 86400;

    File::ensureDirectoryExists($tempBase.'/run-old10');
    touch($tempBase.'/run-old10', $old10);
    File::ensureDirectoryExists($tempBase.'/run-old8');
    touch($tempBase.'/run-old8', $old8);
    File::ensureDirectoryExists($tempBase.'/run-old6');
    touch($tempBase.'/run-old6', $old6);
    File::ensureDirectoryExists($tempBase.'/run-old4');
    touch($tempBase.'/run-old4', $old4);
    File::ensureDirectoryExists($tempBase.'/run-old2');
    touch($tempBase.'/run-old2', $old2);

    try {
        $action = new ReportsPrunerAction;
        $deleted = $action->handle($tempBase, 'days', 7, false, false);

        expect($deleted)->toBe(2)
            ->and(collect(File::directories($tempBase))->map(fn ($p) => basename($p))->sort()->values()->all())
            ->toBe(['run-old2', 'run-old4', 'run-old6']);
    } finally {
        File::deleteDirectory($tempBase);
    }
});

it('does not delete directories when dry run is true', function () {
    $tempBase = sys_get_temp_dir().'/pest-e2e-pruner-'.uniqid();
    File::ensureDirectoryExists($tempBase);

    $now = time();
    foreach (['run1', 'run2', 'run3', 'run4', 'run5'] as $i => $name) {
        $path = $tempBase.'/'.$name;
        File::ensureDirectoryExists($path);
        touch($path, $now - (10 - $i) * 86400);
    }

    try {
        $action = new ReportsPrunerAction;
        $deleted = $action->handle($tempBase, 'items', 2, false, true);

        expect($deleted)->toBe(3)
            ->and(File::directories($tempBase))->toHaveCount(5)
            ->and(collect(File::directories($tempBase))->map(fn ($p) => basename($p))->sort()->values()->all())
            ->toBe(['run1', 'run2', 'run3', 'run4', 'run5']);
    } finally {
        File::deleteDirectory($tempBase);
    }
});

it('deletes all directories when all mode is used', function () {
    $tempBase = sys_get_temp_dir().'/pest-e2e-pruner-'.uniqid();
    File::ensureDirectoryExists($tempBase);

    foreach (['run1', 'run2', 'run3'] as $name) {
        File::ensureDirectoryExists($tempBase.'/'.$name);
    }

    try {
        $action = new ReportsPrunerAction;
        $deleted = $action->handle($tempBase, null, null, true, false);

        expect($deleted)->toBe(3)
            ->and(File::directories($tempBase))->toBeEmpty()
            ->and(collect(File::directories($tempBase))->map(fn ($p) => basename($p))->sort()->values()->all())
            ->toBe([]);
    } finally {
        File::deleteDirectory($tempBase);
    }
});

it('throws on invalid unit', function () {
    $tempBase = sys_get_temp_dir().'/pest-e2e-pruner-'.uniqid();
    File::ensureDirectoryExists($tempBase);

    try {
        (new ReportsPrunerAction)->handle($tempBase, 'bananas', 1, false, false);
    } finally {
        File::deleteDirectory($tempBase);
    }
})->throws(\InvalidArgumentException::class);
