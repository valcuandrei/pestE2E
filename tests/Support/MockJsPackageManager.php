<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Support;

use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Support\JsPackageManager;

/**
 * Test-only mock JsPackageManager for InstallCommand tests.
 * Never runs real node processes.
 */
final class MockJsPackageManager extends JsPackageManager
{
    public bool $hasPlaywright = false;

    public bool $installReturnsSuccess = true;

    public int $installCallCount = 0;

    /** @var array<string, string> */
    public array $detectedLockfilesOverride = [];

    /**
     * When set, {@see getAvailablePackageManagers()} returns this list (deterministic tests).
     *
     * @var list<string>|null
     */
    public ?array $availablePackageManagersOverride = null;

    public function detectedLockfiles(): array
    {
        return $this->detectedLockfilesOverride !== []
            ? $this->detectedLockfilesOverride
            : parent::detectedLockfiles();
    }

    public function getAvailablePackageManagers(): array
    {
        if ($this->availablePackageManagersOverride !== null) {
            return $this->availablePackageManagersOverride;
        }

        return parent::getAvailablePackageManagers();
    }

    public function hasJsAnyDependency(string $dependency): bool
    {
        return $this->hasPlaywright && $dependency === '@playwright/test';
    }

    public function hasJsPackageInstalled(string $package): bool
    {
        return $this->hasPlaywright && $package === '@playwright/test';
    }

    public function installJsPackage(string $package, bool $dev = false, bool $tty = false, ?callable $outputCallback = null): Process|false
    {
        if ($package !== '@playwright/test') {
            return false;
        }

        $this->installCallCount++;

        return new ProcessStub($this->installReturnsSuccess);
    }
}
