<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\AuthTicketStoreContract;
use ValcuAndrei\PestE2E\DTO\AuthTicketDTO;
use ValcuAndrei\PestE2E\Plugin as PestE2EPlugin;
use ValcuAndrei\PestE2E\PublicApi\E2E;
use ValcuAndrei\PestE2E\PublicApi\E2ETargetHandle;

/**
 * Simple array-based auth ticket store for testing
 */
class ArrayAuthTicketStore implements AuthTicketStoreContract
{
    private static array $tickets = [];

    public function store(string $ticket, int|string $userId, string $guard, array $meta, int $ttlSeconds): void
    {
        self::$tickets[$ticket] = new AuthTicketDTO($userId, $guard, $ttlSeconds, $meta);
    }

    public function consume(string $ticket): ?AuthTicketDTO
    {
        if (! isset(self::$tickets[$ticket])) {
            return null;
        }

        $dto = self::$tickets[$ticket];
        unset(self::$tickets[$ticket]);

        return $dto;
    }
}

if (! function_exists('pestE2ERegisterPlugin')) {
    /**
     * Ensure the Pest plugin is registered for this project.
     */
    function pestE2ERegisterPlugin(): void
    {
        $binDir = $GLOBALS['_composer_bin_dir'] ?? getcwd().'/vendor/bin';
        $pluginFile = sprintf('%s/../pest-plugins.json', $binDir);

        $plugins = [];

        if (is_file($pluginFile)) {
            $content = file_get_contents($pluginFile);
            if ($content !== false) {
                $decoded = json_decode($content, true, 512);
                if (is_array($decoded)) {
                    $plugins = array_values(array_filter($decoded, 'is_string'));
                }
            }
        }

        if (! in_array(PestE2EPlugin::class, $plugins, true)) {
            $plugins[] = PestE2EPlugin::class;
            @file_put_contents($pluginFile, json_encode($plugins, JSON_PRETTY_PRINT));
        }
    }
}

pestE2ERegisterPlugin();

if (! function_exists('e2e')) {
    /**
     * @return E2E|E2ETargetHandle
     */
    function e2e(?string $target = null)
    {
        // Ensure bindings are registered before resolving E2E
        $container = app();

        if (! $container->bound(\ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract::class)) {
            // Try to register the full service provider if Laravel Application is available
            if (method_exists($container, 'register')) {
                try {
                    $container->register(\ValcuAndrei\PestE2E\PestE2EServiceProvider::class);
                } catch (\Throwable) {
                    // Fall back to manual bindings if the service provider can't boot
                }
            }

            // Ensure essential bindings exist (may already be set by the service provider)
            if (! $container->bound(\ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract::class)) {
                $container->singleton(\ValcuAndrei\PestE2E\Registries\TargetRegistry::class);
                $container->singleton(\ValcuAndrei\PestE2E\Support\E2EOutputStore::class);

                $container->bind(\ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract::class, \ValcuAndrei\PestE2E\Support\RandomRunIdGenerator::class);
                $container->bind(\ValcuAndrei\PestE2E\Contracts\AuthTicketStoreContract::class, ArrayAuthTicketStore::class);
                $container->bind(\ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract::class, \ValcuAndrei\PestE2E\Support\NullAuthTicketIssuer::class);
                $container->bind(\ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract::class, \ValcuAndrei\PestE2E\Support\TempParamsFileWriter::class);
                $container->bind(\ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract::class, \ValcuAndrei\PestE2E\Actions\DefaultE2EAuthAction::class);
            }

            // Bind the PublicApi E2E class with proper dependencies
            if (! $container->bound(E2E::class)) {
                $container->bind(E2E::class, function ($container) {
                    return new E2E(
                        $container->make(\ValcuAndrei\PestE2E\E2E::class),
                        $container
                    );
                });
            }
        }

        // Always resolve fresh to ensure service provider bindings are available
        $api = app(E2E::class);

        return $target === null
            ? $api
            : $api->targetHandle($target);
    }
}
