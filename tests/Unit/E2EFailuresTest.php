<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('throws a readable exception when the json report contains failures', function () {
    $reportDTO = JsonReportDTO::fakeWithFailedTest();
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');

    expect($reportPath)->not->toBeFalse();
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));

    $reportB64 = base64_encode($reportDTO->toJson());
    $command = 'php -r "file_put_contents('
        .var_export($reportPath, true)
        .', base64_decode('
        .var_export($reportB64, true)
        .'));"';

    try {
        e2e()->target(
            $reportDTO->target,
            fn ($p) => $p
                ->dir(getcwd())
                ->command($command)
                ->report('json', $reportPath)
        );

        expect(fn () => e2e($reportDTO->target)->run())
            ->toThrow(RuntimeException::class, 'E2E failures')
            ->and(fn () => e2e($reportDTO->target)->run())
            ->toThrow(RuntimeException::class, 'test');
    } finally {
        @unlink($reportPath);
    }
});
