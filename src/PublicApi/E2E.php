<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use Closure;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;

/**
 * Public API entry point (stable surface v1).
 */
final class E2E
{
    private static ?self $instance = null;

    private function __construct(
        private readonly CompositionRoot $root,
    ) {}

    /**
     * Get the instance of the E2E class (singleton).
     */
    public static function instance(): self
    {
        $root = CompositionRoot::instance();

        if (! self::$instance instanceof \ValcuAndrei\PestE2E\PublicApi\E2E || self::$instance->root !== $root) {
            self::$instance = new self($root);
        }

        return self::$instance;
    }

    /**
     * Register a project.
     */
    public function project(string $name, Closure $configure): void
    {
        $builder = new ProjectBuilder($name);

        $configure($builder);

        $this->root->registry()->put($builder->toProjectConfig());
    }

    /**
     * Get a project handle.
     */
    public function projectHandle(string $name): E2EProjectHandle
    {
        return new E2EProjectHandle($name);
    }
}
