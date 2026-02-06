<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use JsonException;
use RuntimeException;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\DTO\AuthPayloadDTO;
use ValcuAndrei\PestE2E\DTO\ParamsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;
use ValcuAndrei\PestE2E\Enums\AuthModeType;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;

/**
 * Returned by e2e('frontend')
 */
final class E2ETargetHandle
{
    /** @var array<string,string> */
    private array $env = [];

    /** @var array<string,mixed> */
    private array $params = [];

    private ?ProcessOptionsDTO $options = null;

    public function __construct(
        private readonly string $target,
        private readonly CompositionRoot $root,
        private readonly ParamsFileWriterContract $paramsFileWriter,
        private readonly ProcessRunner $processRunner,
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
     * With auth ticket.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withAuthTicket(AuthPayloadDTO $payload, array $meta = []): self
    {
        /** @var array<string, mixed> $mergedParams */
        $mergedParams = array_replace_recursive($this->params, [
            'auth' => array_merge(
                $payload->toArray(),
                $meta !== [] ? ['meta' => $this->normalizeMeta($meta)] : [],
            ),
        ]);

        $clone = clone $this;
        $clone->params = $mergedParams;

        return $clone;
    }

    /**
     * Issue an auth ticket for a user.
     *
     * @param  array<string, mixed>  $options
     */
    public function actingAs(mixed $user, array $options = []): self
    {
        [$guard, $mode, $meta] = $this->extractAuthOptions($options);

        $issuer = $this->root->authTicketIssuer();
        $ticket = $issuer->issueForUser($user, [
            'guard' => $guard,
            'meta' => $meta,
        ]);

        return $this->withAuthTicket(
            payload: new AuthPayloadDTO(
                ticket: $ticket,
                mode: $mode,
                guard: $guard,
            ),
            meta: $meta,
        );
    }

    /**
     * Alias for actingAs().
     *
     * @param  array<string, mixed>  $options
     */
    public function loginAs(mixed $user, array $options = []): self
    {
        return $this->actingAs($user, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *  0:string,
     *  1:AuthModeType,
     *  2:array<string, mixed>,
     * }
     */
    private function extractAuthOptions(array $options): array
    {
        $guard = 'web';
        if (array_key_exists('guard', $options)) {
            $guard = is_string($options['guard']) ? $options['guard'] : 'web';
        }

        $modeValue = $options['mode'] ?? AuthModeType::SESSION->value;
        if (! is_string($modeValue) && ! is_int($modeValue)) {
            $modeValue = AuthModeType::SESSION->value;
        }

        $mode = AuthModeType::tryFrom($modeValue) ?? AuthModeType::SESSION;

        if (array_key_exists('meta', $options) && is_array($options['meta'])) {
            return [$guard, $mode, $this->normalizeMeta($options['meta'])];
        }

        $meta = $options;
        unset($meta['guard'], $meta['mode']);

        return [$guard, $mode, $this->normalizeMeta($meta)];
    }

    /**
     * @param  array<mixed, mixed>  $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $normalized = [];

        foreach ($meta as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
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
        $this->root->runner()->run(
            targetName: $this->target,
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
        $targetConfig = $this->root->registry()->get($this->target);
        $runId = $this->root->generateRunId();

        /** @var array<string, mixed> */
        $mergedParams = array_replace_recursive($this->params, $params);
        $context = RunContextDTO::make($targetConfig, $runId, $this->env, $mergedParams);

        [$file, $export] = $this->resolveCallTarget($target, $export);

        $paramsDto = new ParamsDTO(
            target: $context->target->name,
            runId: $context->runId,
            params: $context->params,
        );

        $paramsJson = $this->encodeJson($paramsDto);

        $paramsFilePath = $this->paramsFileWriter->write(
            target: $context->target->name,
            runId: $context->runId,
            json: $paramsJson,
        );

        try {
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
                workingDirectory: $context->target->dir,
                env: $context->env,
            ))->withInjectedEnv([
                'PEST_E2E_TARGET' => $context->target->name,
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

            $result = $this->processRunner->run($plan);

            if (! $result->isSuccessful()) {
                throw new RuntimeException(
                    "E2E call failed (exit {$result->exitCode}).\n\n".
                        "TARGET:\n{$resolvedTarget}\n\n".
                        "CMD:\n{$command}\n\n".
                        "CWD:\n{$context->target->dir}\n\n".
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
