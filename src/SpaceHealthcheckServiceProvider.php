<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use GoCPA\SpaceHealthcheck\Commands\SpaceHealthcheckCommand;
use Illuminate\Support\ServiceProvider;

class SpaceHealthcheckServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->publishes(
            [
                __DIR__.'/../config/space-healthcheck.php' => config_path('space-healthcheck.php'),
            ],
            'config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                SpaceHealthcheckCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/space-healthcheck.php', 'space-healthcheck');
    }
}
