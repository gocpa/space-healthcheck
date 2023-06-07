<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use GoCPA\SpaceHealthcheck\Commands\SpaceHealthcheckCommand;
use Illuminate\Support\ServiceProvider;

final class SpaceHealthcheckServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->publishes(
            paths: [
                __DIR__.'/../config/space-healthcheck.php' => config_path('space-healthcheck.php'),
            ],
            groups: 'config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands(
                commands: [
                    SpaceHealthcheckCommand::class,
                ]
            );
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
