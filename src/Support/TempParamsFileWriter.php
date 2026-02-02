<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use RuntimeException;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;

/**
 * @internal
 */
final class TempParamsFileWriter implements ParamsFileWriterContract
{
    /**
     * @param  string|null  $baseDir  (optional) base directory for the params files
     */
    public function __construct(
        private readonly ?string $baseDir = null,
    ) {}

    /**
     * Persist the params JSON and return an absolute file path.
     */
    public function write(string $project, string $runId, string $json): string
    {
        $base = $this->baseDir ?? sys_get_temp_dir();
        $dir = rtrim($base, '/').'/pest-e2e/'.$this->sanitize($project);

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create params dir: {$dir}");
        }

        $path = $dir.'/'.$runId.'.json';

        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException("Unable to write params file: {$path}");
        }

        return $path;
    }

    /**
     * Sanitize the project name.
     */
    private function sanitize(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? 'project';
    }
}
