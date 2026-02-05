<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use Closure;
use Illuminate\Contracts\Container\Container;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;

/**
 * Public API entry point (stable surface v1).
 */
final class E2E
{
    public function __construct(
        private readonly CompositionRoot $root,
        private readonly Container $container,
    ) {}

    /**
     * Register a target.
     */
    public function target(string $name, Closure $configure): void
    {
        $builder = $this->container->make(TargetBuilder::class, ['name' => $name]);

        $configure($builder);

        $this->root->registry()->put($builder->toTargetConfig());
    }

    /**
     * Get a target handle.
     */
    public function targetHandle(string $name): E2ETargetHandle
    {
        return $this->container->make(E2ETargetHandle::class, ['target' => $name]);
    }
}
