<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Install\PestFeatureSuiteInspector;

it('detects commented RefreshDatabase in uses() Feature block', function (): void {
    $pest = <<<'PHP'
<?php

uses(
    Tests\TestCase::class,
    // Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature');

PHP;

    expect(PestFeatureSuiteInspector::hasActiveRefreshDatabase($pest))->toBeFalse()
        ->and(PestFeatureSuiteInspector::hasCommentedRefreshDatabase($pest))->toBeTrue();
});

it('uncomments RefreshDatabase in uses() Feature block', function (): void {
    $pest = <<<'PHP'
<?php

uses(
    Tests\TestCase::class,
    // Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature');

PHP;

    $updated = PestFeatureSuiteInspector::uncommentRefreshDatabase($pest);

    expect(PestFeatureSuiteInspector::hasActiveRefreshDatabase($updated))->toBeTrue()
        ->and($updated)->not->toContain('// Illuminate\Foundation\Testing\RefreshDatabase::class');
});

it('uncomments a commented ->use(RefreshDatabase::class) chain line', function (): void {
    $pest = <<<'PHP'
<?php

pest()->extend(TestCase::class)
    // ->use(RefreshDatabase::class)
    ->in('Feature');

PHP;

    $updated = PestFeatureSuiteInspector::uncommentRefreshDatabase($pest);

    expect(PestFeatureSuiteInspector::hasActiveRefreshDatabase($updated))->toBeTrue()
        ->and($updated)->toContain('->use(RefreshDatabase::class)')
        ->and($updated)->not->toContain('// ->use(RefreshDatabase::class)');
});
