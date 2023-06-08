<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use Illuminate\Support\ServiceProvider;

final class SpaceHealthcheckServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
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
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/space-healthcheck.php', 'space-healthcheck');
    }
}
