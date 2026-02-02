<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Registries;

use ValcuAndrei\PestE2E\DTO\ProjectConfigDTO;

/**
 * @internal
 */
final class ProjectRegistry
{
    /** @var array<string, ProjectConfigDTO> */
    private array $projects = [];

    /**
     * Put a project into the registry.
     */
    public function put(ProjectConfigDTO $project): void
    {
        $this->projects[$project->name] = $project;
    }

    /**
     * Check if a project is registered.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->projects);
    }

    /**
     * Get all projects from the registry.
     *
     * @return array<string, ProjectConfigDTO>
     */
    public function all(): array
    {
        return $this->projects;
    }

    /**
     * Get a project from the registry.
     */
    public function get(string $name): ProjectConfigDTO
    {
        if (! $this->has($name)) {
            throw new \InvalidArgumentException("E2E project not registered: {$name}");
        }

        return $this->projects[$name];
    }
}
