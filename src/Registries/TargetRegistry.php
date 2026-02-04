<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Registries;

use RuntimeException;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;

/**
 * @internal
 */
final class TargetRegistry
{
    /** @var array<string, TargetConfigDTO> */
    private array $targets = [];

    /**
     * Put a target into the registry.
     */
    public function put(TargetConfigDTO $target): void
    {
        $this->targets[$target->name] = $target;
    }

    /**
     * Check if a target is registered.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->targets);
    }

    /**
     * Get all targets from the registry.
     *
     * @return array<string, TargetConfigDTO>
     */
    public function all(): array
    {
        return $this->targets;
    }

    /**
     * Get a target from the registry.
     */
    public function get(string $name): TargetConfigDTO
    {
        if (! $this->has($name)) {
            $known = implode(', ', array_keys($this->targets));
            throw new RuntimeException("Unknown E2E target [{$name}]. Known: {$known}");
        }

        return $this->targets[$name];
    }
}
