<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\E2E;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('calls a module export via the node harness', function () {
    $nodePath = trim((string) shell_exec('command -v node'));

    if ($nodePath === '') {
        $this->markTestSkipped('Node.js not available on PATH.');
    }

    E2E::reset();
    E2E::instance()->useRunIdGenerator(new FixedRunIdGenerator('run-123'));

    $moduleFile = tempnam(sys_get_temp_dir(), 'pest-e2e-call-');
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');

    expect($moduleFile)->not->toBeFalse()
        ->and($reportPath)->not->toBeFalse();

    $modulePath = $moduleFile.'.mjs';
    rename($moduleFile, $modulePath);

    $moduleContents = <<<'JS'
export async function check({ project, runId, params, env }) {
  if (project !== 'frontend') throw new Error('bad project');
  if (runId !== 'run-123') throw new Error('bad run');
  if (params.fromProject !== 'yes') throw new Error('bad params project');
  if (params.fromHandle !== 'yes') throw new Error('bad params handle');
  if (params.fromCall !== 'yes') throw new Error('bad params call');
  if (env.PEST_E2E_PROJECT !== 'frontend') throw new Error('missing env project');
  if (env.PEST_E2E_RUN_ID !== 'run-123') throw new Error('missing env run');
  if (env.FROM_PROJECT !== '1') throw new Error('missing env project flag');
  if (env.FROM_HANDLE !== '1') throw new Error('missing env handle flag');
}
JS;

    file_put_contents($modulePath, $moduleContents);

    try {
        e2e()->project('frontend', fn ($p) => $p
            ->dir(dirname($modulePath))
            ->runner('node')
            ->command('php -r "exit(0);"')
            ->report('json', $reportPath)
            ->env(['FROM_PROJECT' => '1'])
            ->params(['fromProject' => 'yes'])
        );

        e2e('frontend')
            ->withEnv(['FROM_HANDLE' => '1'])
            ->withParams(['fromHandle' => 'yes'])
            ->call($modulePath, 'check', ['fromCall' => 'yes']);
    } finally {
        @unlink($modulePath);
        @unlink($reportPath);
    }
});

it('surfaces a readable error when the export is missing', function () {
    $nodePath = trim((string) shell_exec('command -v node'));

    if ($nodePath === '') {
        $this->markTestSkipped('Node.js not available on PATH.');
    }

    E2E::reset();
    E2E::instance()->useRunIdGenerator(new FixedRunIdGenerator('run-123'));

    $moduleFile = tempnam(sys_get_temp_dir(), 'pest-e2e-call-');
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');

    expect($moduleFile)->not->toBeFalse()
        ->and($reportPath)->not->toBeFalse();

    $modulePath = $moduleFile.'.mjs';
    rename($moduleFile, $modulePath);

    file_put_contents($modulePath, "export async function ok() { return; }\n");

    try {
        e2e()->project('frontend', fn ($p) => $p
            ->dir(dirname($modulePath))
            ->runner('node')
            ->command('php -r "exit(0);"')
            ->report('json', $reportPath)
        );

        expect(fn () => e2e('frontend')->call($modulePath, 'nope'))
            ->toThrow(\RuntimeException::class, 'Export "nope" not found');
    } finally {
        @unlink($modulePath);
        @unlink($reportPath);
    }
});
