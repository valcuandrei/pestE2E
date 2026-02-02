<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class ProcessPlanDTO
{
    public function __construct(
        public ProcessCommandDTO $command,
        public ProcessOptionsDTO $options,
        public ?ParamsDTO $params = null,
        public ?string $paramsJsonInline = null,
        public ?string $paramsJsonFilePath = null,
    ) {}

    /**
     * Create a new ProcessPlanDTO instance with the given params payload.
     */
    public function withParams(?ParamsDTO $params): self
    {
        return new self(
            command: $this->command,
            options: $this->options,
            params: $params,
            paramsJsonInline: $params instanceof \ValcuAndrei\PestE2E\DTO\ParamsDTO ? $this->paramsJsonInline : null,
            paramsJsonFilePath: $params instanceof \ValcuAndrei\PestE2E\DTO\ParamsDTO ? $this->paramsJsonFilePath : null,
        );
    }

    /**
     * Create a new ProcessPlanDTO instance with the given params JSON inline.
     */
    public function withParamsJsonInline(string $paramsJsonInline): self
    {
        return new self(
            command: $this->command,
            options: $this->options,
            params: $this->params,
            paramsJsonInline: $paramsJsonInline,
        );
    }

    /**
     * Create a new ProcessPlanDTO instance with the given params JSON file path.
     */
    public function withParamsJsonFilePath(string $path): self
    {
        return new self(
            command: $this->command,
            options: $this->options,
            params: $this->params,
            paramsJsonInline: null,
            paramsJsonFilePath: $path,
        );
    }

    /**
     * Check if the plan has params.
     */
    public function hasParams(): bool
    {
        return $this->params instanceof \ValcuAndrei\PestE2E\DTO\ParamsDTO;
    }

    /**
     * Check if the plan uses a params file.
     */
    public function usesParamsFile(): bool
    {
        return $this->paramsJsonFilePath !== null;
    }

    /**
     * Check if the plan uses a params inline.
     */
    public function usesParamsInline(): bool
    {
        return $this->paramsJsonInline !== null;
    }
}
