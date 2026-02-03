<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use RuntimeException;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;

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
     * @param array<string,string> $env
     * @return self
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
     * @param array<string,mixed> $params
     * @return self
     */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = array_replace($clone->params, $params);
        return $clone;
    }

    /**
     * With options.
     *
     * @param ProcessOptionsDTO $options
     * @return self
     */
    public function withOptions(ProcessOptionsDTO $options): self
    {
        $clone = clone $this;
        $clone->options = $options;
        return $clone;
    }

    /**
     * run() — run suite, fail on JS failures
     *
     * @return void
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
     *
     * @return void
     */
    public function import(): void
    {
        throw new RuntimeException('e2e()->import() is not implemented yet.');
    }

    /**
     * call(file, export?, params?) — run standalone JS export
     * Shorthand: call("js/tasks/seed.ts:seedDatabase", [...])
     *
     * Stub for now (public surface reserved).
     *
     * @param string $target
     * @param string|null $export
     * @param array<string,mixed> $params
     * @return void
     */
    public function call(string $target, ?string $export = null, array $params = []): void
    {
        throw new RuntimeException('e2e()->call() is not implemented yet.');
    }
}
