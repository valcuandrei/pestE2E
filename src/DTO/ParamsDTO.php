<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class ParamsDTO
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $project,
        public string $runId,
        public array $params,
    ) {}

    /**
     * Convert the params to an array.
     *
     * @return array{project:string, runId:string, params:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'runId' => $this->runId,
            'params' => $this->params,
        ];
    }
}
