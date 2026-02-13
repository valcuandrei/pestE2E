<?php

declare(strict_types=1);

require_once __DIR__.'/src/Collision/Events.php';

use ValcuAndrei\PestE2E\Actions\DefaultE2EAuthAction;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\AuthTicketStoreContract;
use ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\AuthTicketDTO;
use ValcuAndrei\PestE2E\PestE2EServiceProvider;
use ValcuAndrei\PestE2E\Plugin as PestE2EPlugin;
use ValcuAndrei\PestE2E\PublicApi\E2E;
use ValcuAndrei\PestE2E\PublicApi\E2ETargetHandle;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Support\CurrentPhpunitTestContext;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;
use ValcuAndrei\PestE2E\Support\NullAuthTicketIssuer;
use ValcuAndrei\PestE2E\Support\RandomRunIdGenerator;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

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
        $container = app();

        if (! $container->bound(RunIdGeneratorContract::class)) {
            if (method_exists($container, 'register')) {
                try {
                    $container->register(PestE2EServiceProvider::class);
                } catch (\Throwable $e) {
                    // Fall back to manual bindings if the service provider can't boot
                }
            }

            if (! $container->bound(RunIdGeneratorContract::class)) {
                $container->singleton(TargetRegistry::class);
                $container->singleton(E2EOutputStore::class);
                $container->singleton(CurrentPhpunitTestContext::class);

                $container->bind(RunIdGeneratorContract::class, RandomRunIdGenerator::class);
                $container->bind(AuthTicketStoreContract::class, ArrayAuthTicketStore::class);
                $container->bind(AuthTicketIssuerContract::class, NullAuthTicketIssuer::class);
                $container->bind(ParamsFileWriterContract::class, TempParamsFileWriter::class);
                $container->bind(E2EAuthActionContract::class, DefaultE2EAuthAction::class);
            }

            if (! $container->bound(E2E::class)) {
                $container->bind(E2E::class, function ($container) {
                    return new E2E(
                        $container->make(E2E::class),
                        $container
                    );
                });
            }
        }

        $api = app(E2E::class);

        return $target === null
            ? $api
            : $api->targetHandle($target);
    }
}
