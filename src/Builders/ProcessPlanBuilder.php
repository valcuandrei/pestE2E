<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Builders;

use JsonException;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\DTO\ParamsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;

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
     */
    public function build(RunContextDTO $context, ?ProcessOptionsDTO $options = null): ProcessPlanDTO
    {
        $options ??= new ProcessOptionsDTO;

        $commandDto = new ProcessCommandDTO(
            command: $context->project->command,
            workingDirectory: $context->project->dir,
            env: $context->env,
        );

        $commandDto = $commandDto->withInjectedEnv([
            'PEST_E2E_PROJECT' => $context->project->name,
            'PEST_E2E_RUN_ID' => $context->runId,
        ]);

        $plan = new ProcessPlanDTO(
            command: $commandDto,
            options: $options,
        );

        if ($context->params === []) {
            return $plan;
        }

        $paramsDto = new ParamsDTO(
            project: $context->project->name,
            runId: $context->runId,
            params: $context->params,
        );

        $json = $this->encodeJson($paramsDto);

        if (mb_strlen($json) <= $this->maxInlineBytes) {
            $commandDto = $plan->command->withInjectedEnv([
                'PEST_E2E_PARAMS' => $json,
            ]);

            return new ProcessPlanDTO(
                command: $commandDto,
                options: $plan->options,
                params: $paramsDto,
            )->withParamsJsonInline($json);
        }

        $filePath = $this->paramsFileWriter->write($paramsDto->project, $paramsDto->runId, $json);

        $commandDto = $plan->command->withInjectedEnv([
            'PEST_E2E_PARAMS_FILE' => $filePath,
        ]);

        return new ProcessPlanDTO(
            command: $commandDto,
            options: $plan->options,
            params: $paramsDto,
        )->withParamsJsonFilePath($filePath);
    }

    /**
     * Encode the params to JSON.
     *
     *
     *
     * @throws JsonException
     */
    private function encodeJson(ParamsDTO $paramsDto): string
    {
        return json_encode($paramsDto->toArray(), JSON_THROW_ON_ERROR);
    }
}
