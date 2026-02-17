<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Test-only stub for Process-like objects returned by installJsPackage.
 * Extends Process so it satisfies Process|false return type without running real commands.
 */
final class ProcessStub extends Process
{
    public function __construct(
        private readonly bool $successful = true,
    ) {
        parent::__construct(command: ['php', '-v']);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }
}
