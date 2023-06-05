<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use GoCPA\SpaceHealthcheck\Commands\SpaceHealthcheckCommand;
use Illuminate\Support\ServiceProvider;

class SpaceHealthcheckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/space-healthcheck.php',
            'space-healthcheck'
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->publishes([
            __DIR__.'/../config/space-healthcheck.php' => config_path('space-healthcheck.php'),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SpaceHealthcheckCommand::class,
            ]);
        }
    }
}
