<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use Illuminate\Support\ServiceProvider;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Support\DefaultE2EAuthAction;
use ValcuAndrei\PestE2E\Support\NullAuthTicketIssuer;
use ValcuAndrei\PestE2E\Support\RandomRunIdGenerator;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

final class PestE2EServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TargetRegistry::class);

        $this->app->bind(RunIdGeneratorContract::class, RandomRunIdGenerator::class);
        $this->app->bind(AuthTicketIssuerContract::class, NullAuthTicketIssuer::class);
        $this->app->bind(ParamsFileWriterContract::class, TempParamsFileWriter::class);
        $this->app->bind(E2EAuthActionContract::class, DefaultE2EAuthAction::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/testing.php');
        }
    }
}
