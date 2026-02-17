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
        $this->calls[] = [
            'tag' => (array) $this->option('tag'),
            'force' => (bool) $this->option('force'),
        ];

        return self::SUCCESS;
    }
}
