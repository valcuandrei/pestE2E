<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Wrap every `tests/js/*.test.mjs` file with a Pest test so JS-level
 * regressions fail `composer test` inside the pest-e2e package itself.
 * Set-Cookie parser assertions, globalSetup auth-failure semantics, and any
 * future harness-level tests are covered by this one wrapper.
 */
$packageRoot = dirname(__DIR__, 2);
$jsTestFiles = glob($packageRoot.'/tests/js/*.test.mjs') ?: [];

foreach ($jsTestFiles as $testFile) {
    $rel = 'tests/js/'.basename($testFile);

    it("runs the JS suite via node --test: {$rel}", function () use ($packageRoot, $testFile): void {
        $process = new Process(['node', '--test', $testFile], cwd: $packageRoot);
        $process->setTimeout(30);
        $process->run();

        expect($process->getExitCode())->toBe(
            0,
            "node --test failed:\nstdout:\n".$process->getOutput()."\nstderr:\n".$process->getErrorOutput(),
        );
    });
}
