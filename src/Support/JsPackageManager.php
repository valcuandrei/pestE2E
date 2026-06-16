<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class JsPackageManager
{
    /** @var array<string, mixed>|null */
    private ?array $packageJson = null;

    /** @var array<string, bool>|null */
    private ?array $installedPackages = null;

    /** @var array<string, array{name: string, lockfile: string, install: array<string>, installDev: array<string>, init: array<string>, dlx: array<string>}> */
    private array $packageManagers = [
        'pnpm' => [
            'name' => 'pnpm',
            'lockfile' => 'pnpm-lock.yaml',
            'install' => ['i'],
            'installDev' => ['i', '-D'],
            'init' => ['init'],
            'dlx' => ['pnpm', 'dlx'],
        ],
        'yarn' => [
            'name' => 'yarn',
            'lockfile' => 'yarn.lock',
            'install' => ['add'],
            'installDev' => ['add', '-D'],
            'init' => ['init', '-y'],
            'dlx' => ['yarn', 'dlx'],
        ],
        'bun' => [
            'name' => 'bun',
            'lockfile' => 'bun.lockb',
            'install' => ['add'],
            'installDev' => ['add', '-d'],
            'init' => ['init'],
            'dlx' => ['bunx'],
        ],
        'npm' => [
            'name' => 'npm',
            'lockfile' => 'package-lock.json',
            'install' => ['i'],
            'installDev' => ['i', '-D'],
            'init' => ['init', '-y'],
            'dlx' => ['npx'],
        ],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $packageManager = null;

    /** @var array<string> */
    private array $requiredPackageManager = ['pnpm', 'yarn', 'bun', 'npm'];

    /**
     * Set the required package managers.
     *
     * @param  array<string>|string  $packageManagers  The package managers to set.
     */
    public function requiredPackageManager(string|array $packageManagers): void
    {
        if (is_array($packageManagers)) {
            foreach ($packageManagers as $pm) {
                $this->validatePackageManager((string) $pm);
            }

            $this->requiredPackageManager = $packageManagers;

            return;
        }

        $this->validatePackageManager($packageManagers);
        $this->requiredPackageManager = [$packageManagers];
    }

    /**
     * Get the package.json file.
     *
     * @return array<string, mixed>
     */
    public function getPackageJson(): array
    {
        if ($this->packageJson !== null) {
            return $this->packageJson;
        }

        $path = base_path('package.json');

        if (! file_exists($path)) {
            return $this->packageJson = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        /** @var array<string, mixed> */
        $decoded = is_array($decoded ?? null) ? $decoded : [];
        $this->packageJson = $decoded;

        return $this->packageJson;
    }

    /**
     * Get the package.json dependencies.
     *
     * @return array<string, string>
     */
    public function getPackageJsonDependencies(): array
    {
        $deps = $this->getPackageJson()['dependencies'] ?? [];
        /** @var array<string, string> $result */
        $result = is_array($deps) ? $deps : [];

        return $result;
    }

    /**
     * Get the package.json dev dependencies.
     *
     * @return array<string, string>
     */
    public function getPackageJsonDevDependencies(): array
    {
        $deps = $this->getPackageJson()['devDependencies'] ?? [];
        /** @var array<string, string> $result */
        $result = is_array($deps) ? $deps : [];

        return $result;
    }

    /**
     * Get the installed packages.
     *
     * @return array<string, bool>
     */
    public function getInstalledPackages(): array
    {
        if ($this->installedPackages !== null) {
            return $this->installedPackages;
        }

        $this->installedPackages = [];
        $root = base_path('node_modules');

        if (! is_dir($root)) {
            return $this->installedPackages;
        }

        $entries = scandir($root) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }
            if ($entry === '..') {
                continue;
            }
            $path = $root.'/'.$entry;

            if (! is_dir($path)) {
                continue;
            }

            if (str_starts_with($entry, '@')) {
                $scoped = scandir($path) ?: [];

                foreach ($scoped as $name) {
                    if ($name === '.') {
                        continue;
                    }
                    if ($name === '..') {
                        continue;
                    }
                    if (is_dir($path.'/'.$name)) {
                        $this->installedPackages[$entry.'/'.$name] = true;
                    }
                }

                continue;
            }

            $this->installedPackages[$entry] = true;
        }

        return $this->installedPackages;
    }

    /**
     * Get the path to a local binary in node_modules/.bin, or null if not found.
     *
     * @param  string  $binary  The binary name (e.g. 'playwright', 'vite').
     * @param  string|null  $workDir  Directory to check; defaults to base_path().
     */
    public function getLocalBinPath(string $binary, ?string $workDir = null): ?string
    {
        $root = $workDir ?? base_path();
        $path = rtrim($root, '/').'/node_modules/.bin/'.$binary;

        return is_file($path) ? $path : null;
    }

    /**
     * Check if a local binary exists in node_modules/.bin.
     *
     * @param  string  $binary  The binary name (e.g. 'playwright', 'vite').
     * @param  string|null  $workDir  Directory to check; defaults to base_path().
     */
    public function hasLocalBin(string $binary, ?string $workDir = null): bool
    {
        return $this->getLocalBinPath($binary, $workDir) !== null;
    }

    /**
     * Check if a package is installed.
     *
     * @param  string  $package  The package to check.
     */
    public function hasJsPackageInstalled(string $package): bool
    {
        return isset($this->getInstalledPackages()[$package]);
    }

    /**
     * Get all the JS dependencies.
     *
     * @return array<string, string>
     */
    public function allJsDependencies(): array
    {
        return array_merge($this->getPackageJsonDependencies(), $this->getPackageJsonDevDependencies());
    }

    /**
     * Check if a JS dependency is present.
     *
     * @param  string  $dependency  The dependency to check.
     */
    public function hasJsDependency(string $dependency): bool
    {
        return isset($this->getPackageJsonDependencies()[$dependency]);
    }

    /**
     * Check if a JS dev dependency is present.
     *
     * @param  string  $dependency  The dependency to check.
     */
    public function hasJsDevDependency(string $dependency): bool
    {
        return isset($this->getPackageJsonDevDependencies()[$dependency]);
    }

    /**
     * Check if a JS dependency is present.
     *
     * @param  string  $dependency  The dependency to check.
     */
    public function hasJsAnyDependency(string $dependency): bool
    {
        if ($this->hasJsDependency($dependency)) {
            return true;
        }

        return $this->hasJsDevDependency($dependency);
    }

    /**
     * Install a JS package.
     *
     * @param  string  $package  The package to install.
     * @param  bool  $dev  Whether to install as a dev dependency.
     * @param  bool  $tty  Whether to use a TTY.
     * @param  callable(string $type, string $buffer): void|null  $outputCallback  The output callback to use.
     */
    public function installJsPackage(string $package, bool $dev = false, bool $tty = false, ?callable $outputCallback = null): Process|false
    {
        $pm = $this->activePackageManager();

        if (! $pm) {
            return false;
        }

        $command = $dev ? ($pm['installDev'] ?? $pm['install']) : $pm['install'];
        $command = $this->ensureStringArray($command);
        $process = $this->runCommand(command: [...$command, $package], tty: $tty, outputCallback: $outputCallback);

        if (! $process) {
            return false;
        }

        if ($process->isSuccessful()) {
            $this->flushCaches();
        }

        return $process;
    }

    /**
     * Run a binary from node_modules/.bin when present; otherwise via the active package manager's dlx runner (e.g. npx).
     *
     * Uses no process timeout (intended for long-running local CLI work).
     *
     * @param  array<string>  $arguments  Arguments for the binary (excludes the binary name itself).
     * @param  callable(string $type, string $buffer): void|null  $outputCallback
     */
    public function runLocalOrDlxBinary(
        string $binary,
        array $arguments,
        bool $tty = false,
        ?callable $outputCallback = null,
        ?string $workDir = null,
    ): Process|false {
        $cwd = $workDir ?? base_path();
        $bin = $this->getLocalBinPath($binary, $workDir);
        if (is_string($bin) && is_file($bin)) {
            return $this->runProcessWithoutTimeout([$bin, ...$arguments], $cwd, $tty, $outputCallback);
        }

        $pm = $this->activePackageManager();
        if (! $pm || ! isset($pm['dlx'])) {
            return false;
        }

        $prefix = $this->ensureStringArray($pm['dlx']);

        return $this->runProcessWithoutTimeout([...$prefix, $binary, ...$arguments], $cwd, $tty, $outputCallback);
    }

    /**
     * @param  array<string>  $command
     * @param  callable(string $type, string $buffer): void|null  $outputCallback
     */
    private function runProcessWithoutTimeout(
        array $command,
        string $cwd,
        bool $tty,
        ?callable $outputCallback,
    ): Process {
        $process = new Process($command, $cwd);
        $process->setTimeout(null);
        $process->setTty($tty);

        if ($outputCallback !== null) {
            $process->run($outputCallback);
        } else {
            $process->run();
        }

        return $process;
    }

    /**
     * Initialize a project.
     *
     * @param  bool  $tty  Whether to use a TTY.
     * @param  callable(string $type, string $buffer): void|null  $outputCallback  The output callback to use.
     */
    public function initProject(bool $tty = false, ?callable $outputCallback = null): Process|false
    {
        $pm = $this->activePackageManager();

        if (! $pm) {
            return false;
        }

        $init = $this->ensureStringArray($pm['init'] ?? []);
        $process = $this->runCommand(command: $init, tty: $tty, outputCallback: $outputCallback);

        if (! $process) {
            return false;
        }

        if ($process->isSuccessful()) {
            $this->flushCaches();
        }

        return $process;
    }

    /**
     * Run a command with the active package manager.
     *
     * @param  array<string>  $command  The command to run.
     * @param  bool  $tty  Whether to use a TTY.
     * @param  bool  $dlx  Whether to use dlx.
     * @param  callable(string $type, string $buffer): void|null  $outputCallback  The output callback to use.
     * @param  string|null  $workDir  Working directory; defaults to base_path().
     * @param  int|null  $timeout  Timeout in seconds; null means no timeout.
     * @param  array<string, string|null>|null  $env  Environment variables; null means inherit.
     * @return Process|false The process.
     */
    public function runCommand(
        array $command,
        bool $tty = false,
        bool $dlx = false,
        ?callable $outputCallback = null,
        ?string $workDir = null,
        ?int $timeout = null,
        ?array $env = null,
    ): Process|false {
        $pm = $this->activePackageManager();

        if (! $pm) {
            return false;
        }

        if ($dlx && ! isset($pm['dlx'])) {
            $name = isset($pm['name']) && is_string($pm['name']) ? $pm['name'] : 'unknown';
            throw new \InvalidArgumentException("Package manager {$name} does not support dlx");
        }

        $prefix = $dlx && isset($pm['dlx']) ? $pm['dlx'] : [($pm['name'] ?? '')];
        $prefix = $this->ensureStringArray($prefix);
        $cwd = $workDir ?? base_path();
        $process = new Process([...$prefix, ...$command], $cwd, ProcessEnvironment::normalize($env));
        $process->setTty($tty);
        $process->setTimeout($timeout);

        if ($outputCallback) {
            $process->run($outputCallback);
        } else {
            $process->run();
        }

        return $process;
    }

    /**
     * Run a command with dlx.
     *
     * @param  array<string>  $command  The command to run.
     * @param  bool  $tty  Whether to use a TTY.
     * @param  callable(string $type, string $buffer): void|null  $outputCallback  The output callback to use.
     * @param  string|null  $workDir  Working directory; defaults to base_path().
     * @param  int|null  $timeout  Timeout in seconds; null means no timeout.
     * @param  array<string, string|null>|null  $env  Environment variables; null means inherit.
     */
    public function runDlx(
        array $command,
        bool $tty = false,
        ?callable $outputCallback = null,
        ?string $workDir = null,
        ?int $timeout = null,
        ?array $env = null,
    ): Process|false {
        return $this->runCommand(
            command: $command,
            tty: $tty,
            dlx: true,
            outputCallback: $outputCallback,
            workDir: $workDir,
            timeout: $timeout,
            env: $env,
        );
    }

    /**
     * Get the package manager.
     *
     * @return array<string, array<string, mixed>>|false
     */
    public function getPackageManager(): array|false
    {
        if ($this->packageManager !== null) {
            return $this->packageManager === [] ? false : $this->packageManager;
        }

        $this->packageManager = [];

        foreach ($this->packageManagers as $key => $pm) {
            if ($this->binaryExists($pm['name'])) {
                $this->packageManager[$key] = [...$pm, 'available' => true, 'locked' => false];
            }
        }

        foreach ($this->packageManagers as $key => $pm) {
            if (file_exists(base_path($pm['lockfile']))) {
                $existing = $this->packageManager[$key] ?? [...$pm, 'available' => false, 'locked' => true];
                $this->packageManager[$key] = [...$existing, 'locked' => true];
            }
        }

        return $this->packageManager === [] ? false : $this->packageManager;
    }

    /**
     * Get the active package manager.
     *
     * @return array<string, mixed>|false
     */
    public function activePackageManager(): array|false
    {
        $override = $this->resolvePackageManagerOverride();
        if ($override !== null) {
            return $this->resolveForcedPackageManager($override);
        }

        return $this->resolveAutoDetectedPackageManager();
    }

    /**
     * Resolve the package manager override from CLI or config.
     */
    private function resolvePackageManagerOverride(): ?string
    {
        if (CliOptions::$packageManager !== null && CliOptions::$packageManager !== '') {
            return CliOptions::$packageManager;
        }

        if (function_exists('config')) {
            $config = config('pest-e2e.package_manager');
            if (is_string($config) && $config !== '') {
                return $config;
            }
        }

        return null;
    }

    /**
     * Resolve the forced package manager.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function resolveForcedPackageManager(string $pmKey): array
    {
        $this->validatePackageManager($pmKey);

        if (! $this->isPackageManagerAvailable($pmKey)) {
            throw new RuntimeException(
                "Package manager '{$pmKey}' requested but not available. Install it or use a different --run-using value."
            );
        }

        $pms = $this->getPackageManager();
        if ($pms === false || ! isset($pms[$pmKey])) {
            throw new RuntimeException(
                "Package manager '{$pmKey}' requested but could not be resolved."
            );
        }

        return $pms[$pmKey];
    }

    /**
     * Resolve the auto-detected package manager.
     *
     * @return array<string, mixed>|false
     */
    private function resolveAutoDetectedPackageManager(): array|false
    {
        $pms = $this->getPackageManager();
        if (! $pms) {
            return false;
        }

        foreach ($this->requiredPackageManager as $pmKey) {
            if (isset($pms[$pmKey]) && $pms[$pmKey]['locked'] && $pms[$pmKey]['available']) {
                return $pms[$pmKey];
            }
        }

        foreach ($this->requiredPackageManager as $pmKey) {
            if (isset($pms[$pmKey]) && $pms[$pmKey]['available']) {
                return $pms[$pmKey];
            }
        }

        return false;
    }

    /**
     * Check if a binary exists.
     *
     * @param  string  $binary  The binary to check.
     */
    private function binaryExists(string $binary): bool
    {
        return (new ExecutableFinder)->find($binary) !== null;
    }

    /**
     * Get the detected lockfiles.
     *
     * @return array<string, string>
     */
    public function detectedLockfiles(): array
    {
        $found = [];

        foreach ($this->packageManagers as $key => $pm) {
            if (file_exists(base_path($pm['lockfile']))) {
                $found[$key] = $pm['lockfile'];
            }
        }

        return $found;
    }

    /**
     * Package manager keys in registration / priority order (pnpm, yarn, bun, npm, …).
     *
     * @return list<string>
     */
    public function packageManagerKeysInRegistrationOrder(): array
    {
        /** @var list<string> */
        return array_keys($this->packageManagers);
    }

    /**
     * Get the available package managers.
     *
     * @return array<string>
     */
    public function getAvailablePackageManagers(): array
    {
        $pms = $this->getPackageManager();

        if (! $pms) {
            return [];
        }

        return array_keys(array_filter(
            array: $pms,
            callback: fn (array $pm): bool => $pm['available'] === true,
        ));
    }

    /**
     * Package manager key for install-time stubs (e.g. E2ETestCase), from lockfiles only.
     *
     * Uses registration order in {@see $packageManagers} when multiple lockfiles exist.
     * Returns "npm" when no supported lockfile is present.
     */
    public function defaultPackageManagerKeyFromLockfiles(): string
    {
        $lockfiles = $this->detectedLockfiles();

        foreach (array_keys($this->packageManagers) as $key) {
            if (isset($lockfiles[$key])) {
                return $key;
            }
        }

        return 'npm';
    }

    /**
     * Check if a package manager is available.
     *
     * @param  string  $pmKey  The package manager key.
     */
    public function isPackageManagerAvailable(string $pmKey): bool
    {
        $this->validatePackageManager($pmKey);

        return $this->binaryExists($this->packageManagers[$pmKey]['name']);
    }

    /**
     * Ensure value is array of strings.
     *
     * @return array<string>
     */
    private function ensureStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            /** @phpstan-ignore argument.type (command args from package manager config are string) */
            $result[] = strval($item);
        }

        return $result;
    }

    /**
     * Flush the caches.
     */
    private function flushCaches(): void
    {
        $this->packageJson = null;
        $this->installedPackages = null;
    }

    /**
     * Validate a package manager.
     *
     * @param  string  $pm  The package manager to validate.
     */
    private function validatePackageManager(string $pm): void
    {
        if (! isset($this->packageManagers[$pm])) {
            throw new \Exception("Package manager {$pm} is not supported, supported package managers are: ".implode(', ', array_keys($this->packageManagers)));
        }
    }
}
