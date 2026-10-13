<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * One allocated E2E run: where its reports go, where its ownership record
 * and short-lived runtime files live, and the lock that marks it active.
 *
 * The lock is an exclusive `flock` on the run's record file, held from
 * allocation until the run is finished. The operating system releases it if
 * the process dies, which is how an abandoned run is told from an active one.
 *
 * @internal
 */
final class ReportRun
{
    /**
     * @param  string|null  $stateDirectory  Null when the ownership record could not be created; the run then proceeds unregistered.
     * @param  string|null  $runtimeDirectory  Private per-run directory for files that must not outlive the run (storage state).
     * @param  resource|null  $lock  Open, exclusively locked handle on the run record.
     */
    public function __construct(
        public readonly string $target,
        public readonly string $runId,
        public readonly string $baseDirectory,
        public readonly string $reportDirectory,
        public readonly ?string $stateDirectory = null,
        public readonly ?string $runtimeDirectory = null,
        private mixed $lock = null,
    ) {}

    public function __destruct()
    {
        $this->releaseLock();
    }

    /**
     * @return resource|null
     */
    public function lock(): mixed
    {
        return is_resource($this->lock) ? $this->lock : null;
    }

    public function isRegistered(): bool
    {
        return $this->stateDirectory !== null;
    }

    public function releaseLock(): void
    {
        if (is_resource($this->lock)) {
            @flock($this->lock, LOCK_UN);
            @fclose($this->lock);
        }

        $this->lock = null;
    }
}
