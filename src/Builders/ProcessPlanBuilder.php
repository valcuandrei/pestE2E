<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Builders;

use JsonException;
use RuntimeException;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\DTO\ParamsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Support\CliOptions;
use ValcuAndrei\PestE2E\Support\ParallelWorkerContext;

/**
 * @internal
 */
final readonly class ProcessPlanBuilder
{
    /**
     * @param  int  $maxInlineBytes  (optional) maximum number of bytes for the params JSON inline
     */
    public function __construct(
        private ParamsFileWriterContract $paramsFileWriter,
        private int $maxInlineBytes = 8_192,
    ) {}

    /**
     * Create a new ProcessPlanBuilder instance with the given maximum number of bytes for the params JSON inline.
     */
    public function withMaxInlineBytes(int $maxInlineBytes): self
    {
        return new self(
            paramsFileWriter: $this->paramsFileWriter,
            maxInlineBytes: $maxInlineBytes,
        );
    }

    /**
     * Build a new ProcessPlanDTO instance.
     *
     * @param  ProcessOptionsDTO|null  $options  (optional) process options
     *
     * @throws RuntimeException
     */
    public function build(RunContextDTO $context, ?ProcessOptionsDTO $options = null): ProcessPlanDTO
    {
        $options ??= new ProcessOptionsDTO;
        $isHeaded = CliOptions::$browse || CliOptions::$debug;

        $commandDto = new ProcessCommandDTO(
            workingDirectory: $context->target->dir,
            env: $context->env,
        );

        $injected = [
            'PEST_E2E_TARGET' => $context->target->name,
            'PEST_E2E_RUN_ID' => $context->runId,
        ];

        if ($context->reportDirectory !== null) {
            $injected['PEST_E2E_REPORT_DIR'] = $context->reportDirectory;
        }

        if (($token = ParallelWorkerContext::token()) !== null) {
            $injected['TEST_TOKEN'] = $token;
        }

        $commandDto = $commandDto->withInjectedEnv($injected);

        $options = $options->withTimeoutSeconds(
            $isHeaded
                ? 60 * 60 // 1 hour
                : $options->timeoutSeconds
        );

        $plan = new ProcessPlanDTO(
            command: $commandDto,
            options: $options,
            testFilter: $context->testFilter,
            headed: $isHeaded,
            debug: CliOptions::$debug,
            commandPreview: $this->commandPreview($context->testFilter, $isHeaded, CliOptions::$debug),
            reportDirectory: $context->reportDirectory,
        );

        if ($context->params === []) {
            return $plan;
        }

        $paramsDto = new ParamsDTO(
            target: $context->target->name,
            runId: $context->runId,
            params: $context->params,
        );

        $json = $this->encodeJson($paramsDto);

        if (strlen($json) <= $this->maxInlineBytes) {
            $commandDto = $plan->command->withInjectedEnv([
                'PEST_E2E_PARAMS' => $json,
            ]);

            return new ProcessPlanDTO(
                command: $commandDto,
                options: $plan->options,
                testFilter: $plan->testFilter,
                headed: $plan->headed,
                debug: $plan->debug,
                commandPreview: $plan->commandPreview,
                params: $paramsDto,
                reportDirectory: $plan->reportDirectory,
            )->withParamsJsonInline($json);
        }

        $filePath = $this->paramsFileWriter->write($paramsDto->target, $paramsDto->runId, $json);

        $commandDto = $plan->command->withInjectedEnv([
            'PEST_E2E_PARAMS_FILE' => $filePath,
        ]);

        return new ProcessPlanDTO(
            command: $commandDto,
            options: $plan->options,
            testFilter: $plan->testFilter,
            headed: $plan->headed,
            debug: $plan->debug,
            commandPreview: $plan->commandPreview,
            params: $paramsDto,
            reportDirectory: $plan->reportDirectory,
        )->withParamsJsonFilePath($filePath);
    }

    /**
     * Encode the params to JSON.
     *
     *
     * @throws JsonException
     */
    private function encodeJson(ParamsDTO $paramsDto): string
    {
        return json_encode($paramsDto->toArray(), JSON_THROW_ON_ERROR);
    }

    private function commandPreview(?string $testFilter, bool $headed, bool $debug): string
    {
        $parts = ['playwright', 'test', '--reporter', 'json'];

        if (is_string($testFilter) && $testFilter !== '') {
            $parts[] = '--grep';
            $parts[] = $testFilter;
        }

        if ($headed) {
            $parts[] = '--headed';
        }

        if ($debug) {
            $parts[] = '--debug';
        }

        return implode(' ', $parts);
    }
}
