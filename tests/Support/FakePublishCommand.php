<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Support;

use Illuminate\Console\Command;

/**
 * Test-only fake that intercepts vendor:publish calls without running real publish.
 */
final class FakePublishCommand extends Command
{
    protected $signature = 'vendor:publish {--force : Overwrite existing files} {--tag=* : One or many tags} {--provider= : The service provider that has publishes}';

    /** @var array<int, array{tag: array<string>, force: bool}> */
    public array $calls = [];

    public function handle(): int
    {
        $tags = (array) $this->option('tag');
        $this->calls[] = [
            'tag' => $tags,
            'force' => (bool) $this->option('force'),
        ];

        if (in_array('pest-e2e-test-case', $tags, true)) {
            $this->publishE2ETestCaseStub();
        }

        return self::SUCCESS;
    }

    private function publishE2ETestCaseStub(): void
    {
        $stubPath = dirname(__DIR__, 2).'/stubs/tests/E2ETestCase.stub';
        $targetPath = base_path('tests/E2ETestCase.php');

        if (is_file($stubPath)) {
            $dir = dirname($targetPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($stubPath, $targetPath);
        }
    }
}
