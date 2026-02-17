<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

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
     * @return Process|false The process.
     */
    public function runCommand(array $command, bool $tty = false, bool $dlx = false, ?callable $outputCallback = null): Process|false
    {
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
        $process = new Process([...$prefix, ...$command], base_path());
        $process->setTty($tty);
        $process->setTimeout(null);

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
     */
    public function runDlx(array $command, bool $tty = false, ?callable $outputCallback = null): Process|false
    {
        return $this->runCommand(command: $command, tty: $tty, dlx: true, outputCallback: $outputCallback);
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
        $cmd = PHP_OS_FAMILY === 'Windows' ? ['where', $binary] : ['sh', '-lc', 'command -v '.escapeshellarg($binary)];
        $process = new Process($cmd);
        $process->run();

        return $process->isSuccessful();
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
