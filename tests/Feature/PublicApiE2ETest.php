<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('runs via the public e2e helper using container bindings', function () {
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $reportDTO = JsonReportDTO::fakeWithPassedTest();

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));

    $reportB64 = base64_encode($reportDTO->toJson());
    $command = 'php -r "file_put_contents('
        .var_export($reportPath, true)
        .', base64_decode('
        .var_export($reportB64, true)
        .'));"';

    try {
        e2e()->target($reportDTO->target, fn ($p) => $p
            ->dir(getcwd())
            ->command($command)
            ->report('json', $reportPath)
        );

        e2e($reportDTO->target)->run();

        $data = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        expect($data['target'])->toBe($reportDTO->target)
            ->and($data['runId'])->toBe($reportDTO->runId);
    } finally {
        @unlink($reportPath);
    }
});
