<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValcuAndrei\PestE2E\Support\JsPackageManager;

/**
 * Install run: plan, I/O delegates, vendor publish, Pest.php cache, and package-manager memo.
 */
final class InstallContext
{
    /** In-memory cache of `tests/Pest.php` contents after first read or after a step updates it. */
    public ?string $pestPhp = null;

    /** Memoized result of {@see InstallPackageManagerResolver::e2ePackageManagerKey()}. */
    public ?string $memoizedE2ePackageManagerKey = null;

    /**
     * @param  \Closure(array<string>, bool): int  $vendorPublish
     * @param  \Closure(string): mixed  $getOption
     */
    public function __construct(
        public readonly InstallPlan $plan,
        private readonly Command $command,
        private readonly InputInterface $input,
        private readonly OutputInterface $output,
        public readonly JsPackageManager $jsPackageManager,
        public readonly bool $force,
        public readonly bool $hasPlaywrightInstalled,
        private readonly \Closure $vendorPublish,
        private readonly \Closure $getOption,
    ) {}

    /**
     * @param  string  $name  Artisan option name.
     */
    public function option(string $name): mixed
    {
        return ($this->getOption)($name);
    }

    /**
     * @param  array<string>  $tags
     */
    public function publish(array $tags, bool $forcePublish = false): int
    {
        return ($this->vendorPublish)($tags, $forcePublish);
    }

    /**
     * Whether console output is quiet (e.g. `-q`).
     */
    public function isQuiet(): bool
    {
        return $this->output->isQuiet();
    }

    /**
     * Whether the command input is interactive (TTY prompts allowed).
     */
    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * Write a styled info line via the backing Artisan command.
     */
    public function info(string $message): void
    {
        $this->command->info($message);
    }

    /**
     * Write a styled warning line via the backing Artisan command.
     */
    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    /**
     * Write a styled error line via the backing Artisan command.
     */
    public function error(string $message): void
    {
        $this->command->error($message);
    }

    /**
     * Raw stdout/stderr chunk (e.g. npm install or Playwright download output).
     */
    public function writeToOutput(string $buffer): void
    {
        $this->output->write($buffer);
    }

    /**
     * Absolute path to `tests/Pest.php`.
     */
    public function pestPhpPath(): string
    {
        return base_path('tests/Pest.php');
    }

    /**
     * Whether `tests/Pest.php` exists on disk.
     */
    public function pestPhpExists(): bool
    {
        return file_exists($this->pestPhpPath());
    }

    /**
     * Cached contents of `tests/Pest.php`, or null if missing/unreadable.
     */
    public function getPestPhp(): ?string
    {
        if ($this->pestPhp !== null) {
            return $this->pestPhp;
        }

        $path = $this->pestPhpPath();
        $content = $this->pestPhpExists() ? file_get_contents($path) : false;
        $this->pestPhp = $content !== false ? $content : null;

        return $this->pestPhp;
    }

    /**
     * Whether Pest.php already references `E2ETestCase::class`.
     */
    public function pestPhpHasE2ETestCase(): bool
    {
        $pest = $this->getPestPhp();

        if (! is_string($pest)) {
            return false;
        }

        return str_contains($pest, 'E2ETestCase::class');
    }

    /**
     * Whether Pest.php already applies `RefreshDatabase` to the Feature suite.
     */
    public function pestPhpHasFeatureRefreshDatabase(): bool
    {
        $pest = $this->getPestPhp();

        if (! is_string($pest)) {
            return false;
        }

        return PestFeatureSuiteInspector::hasActiveRefreshDatabase($pest);
    }
}
