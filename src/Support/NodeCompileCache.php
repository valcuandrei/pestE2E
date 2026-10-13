<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Points the Playwright child process at Node's on-disk module compile cache.
 *
 * Every pest-e2e run starts a fresh `playwright test` process, which forks a
 * fresh worker; both load and compile several megabytes of Playwright's own
 * JavaScript before the first spec callback. `NODE_COMPILE_CACHE` (Node
 * 22.1+) lets Node reuse the compiled code between those processes. It is a
 * Node runtime feature: the CLI arguments, the process model and everything
 * Playwright Test does are untouched.
 *
 * Rules, in order:
 *
 * 1. `NODE_COMPILE_CACHE` or `NODE_DISABLE_COMPILE_CACHE` already defined —
 *    by the target's `env([...])` or inherited from the Pest process — always
 *    wins. The value is left exactly as it is, whatever the config says.
 * 2. `pest-e2e.node_compile_cache.enabled = false` → nothing is set.
 * 3. A `node` older than 22.1, or one that cannot be probed → nothing is set,
 *    so older runtimes launch exactly as before.
 * 4. Otherwise the variable is set to a per-user, per-project directory under
 *    the system temp directory. Every Pest worker of a project resolves the
 *    same path; unrelated projects never share one.
 *
 * Nothing here can fail a run. Node itself ignores a cache directory it
 * cannot use, and when pest-e2e cannot prepare its own directory it leaves
 * the variable unset and says so once on STDERR.
 *
 * @internal
 */
final class NodeCompileCache
{
    public const string ENV_VAR = 'NODE_COMPILE_CACHE';

    public const string DISABLE_ENV_VAR = 'NODE_DISABLE_COMPILE_CACHE';

    public const string MINIMUM_NODE_VERSION = '22.1.0';

    private const string DIRECTORY_NAMESPACE = 'pest-e2e-node-compile-cache';

    /**
     * Probed `node` version per PATH; `null` when the probe failed.
     *
     * @var array<string, string|null>
     */
    private static array $nodeVersions = [];

    /**
     * Whether each candidate directory was usable, so it is checked once per
     * PHP process rather than once per test.
     *
     * @var array<string, bool>
     */
    private static array $preparedDirectories = [];

    /**
     * @param  (Closure(array<string, string|null>, string): ?string)|null  $nodeVersionResolver  Returns the `node` version the child will run (e.g. "24.16.0"), or null when unknown.
     * @param  string|null  $directory  Cache directory to use instead of the per-project default.
     * @param  (Closure(string): ?string)|null  $inheritedEnvironment  Reads a variable the child would inherit from this process; null when it is not defined.
     * @param  (Closure(string): void)|null  $diagnostic  Receives the one-line explanation when the cache directory cannot be used.
     */
    public function __construct(
        private readonly ?Closure $nodeVersionResolver = null,
        private readonly ?string $directory = null,
        private readonly ?Closure $inheritedEnvironment = null,
        private readonly ?Closure $diagnostic = null,
    ) {}

    /**
     * Return the child environment, with the compile cache directory added when it applies.
     *
     * @param  array<string, string|null>  $env  Environment pest-e2e passes to the Playwright process.
     * @return array<string, string|null>
     */
    public function apply(array $env, string $workingDirectory): array
    {
        if ($this->isUserDefined($env)) {
            return $env;
        }

        if (! $this->isEnabled()) {
            return $env;
        }

        if (! self::supportsCompileCache($this->nodeVersion($env, $workingDirectory))) {
            return $env;
        }

        $directory = $this->directory();

        if (! $this->prepareDirectory($directory)) {
            return $env;
        }

        $env[self::ENV_VAR] = $directory;

        return $env;
    }

    /**
     * The directory pest-e2e uses when nothing else is configured.
     */
    public function directory(): string
    {
        return $this->directory ?? ReportPathPolicy::fallbackDirectory(self::DIRECTORY_NAMESPACE);
    }

    /**
     * True for Node 22.1.0 and newer, the first release with `NODE_COMPILE_CACHE`.
     */
    public static function supportsCompileCache(?string $nodeVersion): bool
    {
        if ($nodeVersion === null) {
            return false;
        }

        if (preg_match('/^v?(\d+\.\d+\.\d+)/', trim($nodeVersion), $matches) !== 1) {
            return false;
        }

        return version_compare($matches[1], self::MINIMUM_NODE_VERSION, '>=');
    }

    /**
     * Test hook: forget probed Node versions and prepared directories.
     *
     * @internal
     */
    public static function resetState(): void
    {
        self::$nodeVersions = [];
        self::$preparedDirectories = [];
    }

    /**
     * @param  array<string, string|null>  $env
     */
    private function isUserDefined(array $env): bool
    {
        foreach ([self::ENV_VAR, self::DISABLE_ENV_VAR] as $name) {
            if (array_key_exists($name, $env)) {
                return true;
            }

            if ($this->inherited($name) !== null) {
                return true;
            }
        }

        return false;
    }

    private function isEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        try {
            $value = config('pest-e2e.node_compile_cache.enabled', true);
        } catch (Throwable) {
            return false;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return (bool) $value;
    }

    /**
     * @param  array<string, string|null>  $env
     */
    private function nodeVersion(array $env, string $workingDirectory): ?string
    {
        if ($this->nodeVersionResolver instanceof Closure) {
            return ($this->nodeVersionResolver)($env, $workingDirectory);
        }

        $path = array_key_exists('PATH', $env) ? (string) $env['PATH'] : (string) $this->inherited('PATH');

        if (! array_key_exists($path, self::$nodeVersions)) {
            self::$nodeVersions[$path] = $this->probeNodeVersion($env, $workingDirectory);
        }

        return self::$nodeVersions[$path];
    }

    /**
     * Ask the `node` the child will resolve for its version.
     *
     * The Playwright binary starts through `#!/usr/bin/env node`, which looks
     * `node` up on the child's PATH. Going through the same `env` reproduces
     * that lookup; a bare `node` would be located with this process's PATH
     * instead, which differs when a target overrides PATH.
     *
     * @param  array<string, string|null>  $env
     */
    private function probeNodeVersion(array $env, string $workingDirectory): ?string
    {
        $command = is_executable('/usr/bin/env') ? ['/usr/bin/env', 'node', '--version'] : ['node', '--version'];

        try {
            $process = new Process(
                command: $command,
                cwd: is_dir($workingDirectory) ? $workingDirectory : null,
                env: ProcessEnvironment::normalize($env),
                timeout: 10,
            );
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $version = trim($process->getOutput());

            return $version === '' ? null : ltrim($version, 'v');
        } catch (Throwable) {
            return null;
        }
    }

    private function prepareDirectory(string $directory): bool
    {
        if (array_key_exists($directory, self::$preparedDirectories)) {
            return self::$preparedDirectories[$directory];
        }

        $problem = $this->directoryProblem($directory);

        if ($problem !== null) {
            $this->report(sprintf(
                'pest-e2e: Node compile cache not used — %s (%s). Set %s to choose a directory, or pest-e2e.node_compile_cache.enabled to false to turn this off.',
                $problem,
                $directory,
                self::ENV_VAR,
            ));
        }

        return self::$preparedDirectories[$directory] = $problem === null;
    }

    /**
     * Create the directory if needed and explain why it cannot be trusted, if it cannot.
     *
     * The path is predictable and lives in a shared temp directory, and the
     * cache holds code Node will execute, so a directory someone else could
     * have prepared or can write to is refused.
     */
    private function directoryProblem(string $directory): ?string
    {
        if ($directory === '') {
            return 'no directory could be resolved';
        }

        if (is_link($directory)) {
            return 'the directory is a symbolic link';
        }

        if (! is_dir($directory) && ! ReportPathPolicy::ensureDirectory($directory)) {
            return 'the directory could not be created';
        }

        if (! is_dir($directory)) {
            return 'the path is not a directory';
        }

        if (! is_writable($directory)) {
            return 'the directory is not writable';
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        if (function_exists('posix_geteuid') && @fileowner($directory) !== posix_geteuid()) {
            return 'the directory belongs to another user';
        }

        $permissions = @fileperms($directory);

        if ($permissions === false || ($permissions & 0022) !== 0) {
            return 'the directory is writable by other users';
        }

        return null;
    }

    private function inherited(string $name): ?string
    {
        if ($this->inheritedEnvironment instanceof Closure) {
            return ($this->inheritedEnvironment)($name);
        }

        foreach ([$_SERVER, $_ENV] as $source) {
            if (isset($source[$name]) && is_string($source[$name])) {
                return $source[$name];
            }
        }

        $value = getenv($name);

        return is_string($value) ? $value : null;
    }

    private function report(string $message): void
    {
        if ($this->diagnostic instanceof Closure) {
            ($this->diagnostic)($message);

            return;
        }

        fwrite(STDERR, "\n{$message}\n");
    }
}
