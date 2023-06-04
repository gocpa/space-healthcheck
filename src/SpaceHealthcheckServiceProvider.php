<?php

namespace GoCPA\SpaceHealthcheck;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use GoCPA\SpaceHealthcheck\Commands\SpaceHealthcheckCommand;

class SpaceHealthcheckServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('space-healthcheck')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_space-healthcheck_table')
            ->hasCommand(SpaceHealthcheckCommand::class);
    }
}
