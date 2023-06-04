<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use GoCPA\SpaceHealthcheck\Commands\SpaceHealthcheckCommand;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
            ->hasRoute('web')
            ->hasCommand(SpaceHealthcheckCommand::class)
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->endWith(function (InstallCommand $command) {
                        $command->call(SpaceHealthcheckCommand::class);
                        $command->call('config:clear');
                        $command->info('Open this link in browser: '.route('space.check', ['secretKey' => config('space-healthcheck.secretKey')]));
                    });
            });
    }
}
