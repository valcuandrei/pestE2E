<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use Illuminate\Support\ServiceProvider;
use ValcuAndrei\PestE2E\Actions\DefaultE2EAuthAction;
use ValcuAndrei\PestE2E\Commands\PublishCommand;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\AuthTicketStoreContract;
use ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Support\CacheAuthTicketStore;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;
use ValcuAndrei\PestE2E\Support\LaravelAuthTicketIssuer;
use ValcuAndrei\PestE2E\Support\RandomRunIdGenerator;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

final class PestE2EServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TargetRegistry::class);
        $this->app->singleton(E2EOutputStore::class);

        $this->mergeConfigFrom(__DIR__.'/../config/pest-e2e.php', 'pest-e2e');

        $this->app->bind(RunIdGeneratorContract::class, RandomRunIdGenerator::class);
        $this->app->bind(AuthTicketStoreContract::class, CacheAuthTicketStore::class);
        $this->app->bind(AuthTicketIssuerContract::class, LaravelAuthTicketIssuer::class);
        $this->app->bind(ParamsFileWriterContract::class, TempParamsFileWriter::class);
        $this->app->bind(E2EAuthActionContract::class, DefaultE2EAuthAction::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/pest-e2e.php' => config_path('pest-e2e.php'),
            ], 'pest-e2e-config');

            $this->publishes([
                __DIR__.'/../resources/js/pest-e2e' => resource_path('js/pest-e2e'),
            ], 'pest-e2e-js');

            $this->commands([
                PublishCommand::class,
            ]);
        }

        if (config()->boolean('pest-e2e.auth.route_enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/pest-e2e-testing.php');
        }
    }
}
