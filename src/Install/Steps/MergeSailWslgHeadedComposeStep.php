<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Merges WSLg display and socket volume mounts into Sail `laravel.test` for headed Playwright in Docker.
 */
final class MergeSailWslgHeadedComposeStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->mergeSailWslgHeaded;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        $composePath = InstallProjectProbe::resolveComposeFilePath();
        if ($this->mergeSailWslgHeadedCompose() === Command::SUCCESS) {
            if (! $ctx->isQuiet() && is_string($composePath)) {
                $ctx->info('WSLg headed-mode environment and volumes merged into '.basename($composePath).' (laravel.test).');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->warn('Could not merge Sail WSLg config. Add the "Headed Mode in Sail" block from README manually to your compose file.');
        }

        return StepResult::ok();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if (! $ctx->isQuiet() && InstallProjectProbe::sailProjectDetected() && InstallProjectProbe::composeFileHasSailWslgHeadedConfig()) {
            $ctx->info('Sail compose file already includes WSLg headed-mode settings.');
        }
    }

    /**
     * Parse compose YAML, merge WSLg env/volumes into `services.laravel.test`, and write the file back.
     */
    private function mergeSailWslgHeadedCompose(): int
    {
        $path = InstallProjectProbe::resolveComposeFilePath();
        if ($path === null) {
            return Command::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return Command::FAILURE;
        }

        try {
            $data = Yaml::parse($raw);
        } catch (\Throwable) {
            return Command::FAILURE;
        }

        if (! is_array($data)) {
            return Command::FAILURE;
        }

        $services = $data['services'] ?? null;
        if (! is_array($services)) {
            return Command::FAILURE;
        }

        $service = $services['laravel.test'] ?? null;
        if (! is_array($service)) {
            return Command::FAILURE;
        }

        $env = $service['environment'] ?? [];
        if (! is_array($env)) {
            return Command::FAILURE;
        }
        $service['environment'] = $this->mergeWslgEnvironment($env);

        $volumes = $service['volumes'] ?? [];
        if (! is_array($volumes) || ($volumes !== [] && ! array_is_list($volumes))) {
            return Command::FAILURE;
        }
        $listVolumes = $volumes;
        $merged = $this->mergeWslgVolumes($listVolumes);
        $service['volumes'] = $merged;

        $services['laravel.test'] = $service;
        $data['services'] = $services;

        $flags = Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;
        $dumped = Yaml::dump($data, 8, 4, $flags);
        if ($dumped === '' || $dumped === '0') {
            return Command::FAILURE;
        }

        return file_put_contents($path, $dumped) !== false ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Add DISPLAY/WAYLAND/PULSE/XDG keys when missing (supports map or list-style `environment:` entries).
     *
     * @param  array<mixed>  $env
     * @return array<mixed>
     */
    private function mergeWslgEnvironment(array $env): array
    {
        $wslg = [
            'DISPLAY' => '${DISPLAY}',
            'WAYLAND_DISPLAY' => '${WAYLAND_DISPLAY}',
            'XDG_RUNTIME_DIR' => '${XDG_RUNTIME_DIR}',
            'PULSE_SERVER' => '${PULSE_SERVER}',
        ];

        if ($env !== [] && array_is_list($env)) {
            $out = $env;
            foreach ($wslg as $key => $value) {
                $found = false;
                foreach ($env as $entry) {
                    if (! is_string($entry)) {
                        continue;
                    }
                    $trimmed = trim($entry);
                    if (str_starts_with($trimmed, $key.'=') || str_starts_with($trimmed, $key.':')) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $out[] = $key.'='.$value;
                }
            }

            return $out;
        }

        foreach ($wslg as $key => $value) {
            if (! array_key_exists($key, $env)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Append `/mnt/wslg` and X11 socket mounts when not already present.
     *
     * @param  list<mixed>  $volumes
     * @return list<string>
     */
    private function mergeWslgVolumes(array $volumes): array
    {
        $extra = ['/mnt/wslg:/mnt/wslg', '/tmp/.X11-unix:/tmp/.X11-unix'];
        $out = [];
        foreach ($volumes as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }
        foreach ($extra as $mount) {
            if (! in_array($mount, $out, true)) {
                $out[] = $mount;
            }
        }

        return $out;
    }
}
