<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use JsonException;
use RuntimeException;
use ValcuAndrei\PestE2E\DTO\ParamsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

/**
 * Returned by e2e('frontend')
 */
final class E2EProjectHandle
{
    /** @var array<string,string> */
    private array $env = [];

    /** @var array<string,mixed> */
    private array $params = [];

    private ?ProcessOptionsDTO $options = null;

    public function __construct(
        private readonly string $project,
    ) {}

    /**
     * With environment variables.
     *
     * @param  array<string,string>  $env
     */
    public function withEnv(array $env): self
    {
        $clone = clone $this;
        $clone->env = array_replace($clone->env, $env);

        return $clone;
    }

    /**
     * With parameters.
     *
     * @param  array<string,mixed>  $params
     */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = array_replace($clone->params, $params);

        return $clone;
    }

    /**
     * With options.
     */
    public function withOptions(ProcessOptionsDTO $options): self
    {
        $clone = clone $this;
        $clone->options = $options;

        return $clone;
    }

    /**
     * run() — run suite, fail on JS failures
     */
    public function run(): void
    {
        $runner = CompositionRoot::instance()->runner();

        $runner->run(
            projectName: $this->project,
            env: $this->env,
            params: $this->params,
            options: $this->options,
        );
    }

    /**
     * import() — import JS tests as Pest tests
     * Stub for now (public surface reserved).
     */
    public function import(): never
    {
        throw new RuntimeException('e2e()->import() is not implemented yet.');
    }

    /**
     * call(file, export?, params?) — run standalone JS export
     * Shorthand: call("js/tasks/seed.ts:seedDatabase", [...])
     *
     * Stub for now (public surface reserved).
     *
     * @param  array<string,mixed>  $params
     */
    public function call(string $target, ?string $export = null, array $params = []): void
    {
        $root = CompositionRoot::instance();
        $project = $root->registry()->get($this->project);
        $runId = $root->generateRunId();

        /** @var array<string, mixed> */
        $mergedParams = array_replace_recursive($this->params, $params);
        $context = RunContextDTO::make($project, $runId, $this->env, $mergedParams);

        [$file, $export] = $this->resolveCallTarget($target, $export);

        $paramsDto = new ParamsDTO(
            project: $context->project->name,
            runId: $context->runId,
            params: $context->params,
        );

        $paramsJson = $this->encodeJson($paramsDto);

        $paramsFilePath = (new TempParamsFileWriter)->write(
            project: $context->project->name,
            runId: $context->runId,
            json: $paramsJson,
        );

        $harness = $this->callHarnessPath();
        $resolvedTarget = $export !== null ? "{$file}:{$export}" : $file;

        $commandParts = [
            'node',
            escapeshellarg($harness),
            escapeshellarg($file),
        ];

        if ($export !== null) {
            $commandParts[] = escapeshellarg($export);
        }

        $command = implode(' ', $commandParts);

        $commandDto = (new ProcessCommandDTO(
            command: $command,
            workingDirectory: $context->project->dir,
            env: $context->env,
        ))->withInjectedEnv([
            'PEST_E2E_PROJECT' => $context->project->name,
            'PEST_E2E_RUN_ID' => $context->runId,
            'PEST_E2E_PARAMS' => $paramsJson,
            'PEST_E2E_PARAMS_FILE' => $paramsFilePath,
        ]);

        $plan = new ProcessPlanDTO(
            command: $commandDto,
            options: $this->options ?? new ProcessOptionsDTO,
            params: $paramsDto,
            paramsJsonInline: $paramsJson,
            paramsJsonFilePath: $paramsFilePath,
        );

        try {
            $result = (new ProcessRunner)->run($plan);

            if (! $result->isSuccessful()) {
                throw new RuntimeException(
                    "E2E call failed (exit {$result->exitCode}).\n\n".
                        "TARGET:\n{$resolvedTarget}\n\n".
                        "CMD:\n{$command}\n\n".
                        "CWD:\n{$context->project->dir}\n\n".
                        "STDOUT:\n{$result->stdout}\n\n".
                        "STDERR:\n{$result->stderr}"
                );
            }
        } finally {
            @unlink($paramsFilePath);
        }
    }

    /**
     * Resolve the call target and export name.
     *
     * @return array{0:string,1:?string}
     */
    private function resolveCallTarget(string $target, ?string $export): array
    {
        if ($export !== null) {
            return [$target, $export];
        }

        $pos = strrpos($target, ':');

        if ($pos === false) {
            return [$target, null];
        }

        $file = substr($target, 0, $pos);
        $candidate = substr($target, $pos + 1);

        if ($file === '' || $candidate === '') {
            return [$target, null];
        }

        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return [$target, null];
        }

        return [$file, $candidate];
    }

    /**
     * Get the call harness path.
     */
    private function callHarnessPath(): string
    {
        $path = dirname(__DIR__, 2).'/resources/node/call.mjs';

        if (! is_file($path)) {
            throw new RuntimeException("E2E call harness not found at {$path}");
        }

        return $path;
    }

    /**
     * Encode the params to JSON.
     *
     * @throws JsonException
     */
    private function encodeJson(ParamsDTO $paramsDto): string
    {
        return json_encode($paramsDto->toArray(), JSON_THROW_ON_ERROR);
    }
}
