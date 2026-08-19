<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Http\Controllers;

use Composer\InstalledVersions;
use GoCPA\SpaceHealthcheck\Git;
use Illuminate\Http\JsonResponse;
use OutOfBoundsException;
use Spatie\Health\ResultStores\ResultStore;

class SpaceHealthCheckController
{
    /**
     * Выводит результат для мониторинга
     */
    public function __invoke(): JsonResponse
    {
        $result = [];
        try {
            // Порядок важен: getHealthData() — единственная секция, которая может
            // бросить (spatie/laravel-health опционален), поэтому она идёт последней.
            // Иначе верхний catch обрубает всё, что присваивается после неё.
            $result['generatedAt'] = now()->timestamp;
            $result['environment'] = config('app.env');
            $result['name'] = config('app.name');
            $result['env'] = config('app.env');
            $result['debug'] = config('app.debug');
            $result['git'] = $this->getGitInfo();
            $result['composer'] = $this->getComposerInfo();
            $result['health'] = $this->getHealthData();
        } catch (\Throwable $th) {
            $result['exception'] = $th->getMessage();
        }

        return new JsonResponse($result);
    }

    /** @return array<string, string|int|null> */
    private function getGitInfo(): array
    {
        try {
            return app(Git::class)->run();
        } catch (\Throwable $th) {
            return [
                'exception' => $th->getMessage(),
            ];
        }
    }

    /** @return array<string,string|null> */
    private function getComposerInfo(): array
    {
        $packages = [
            'barryvdh/laravel-debugbar',
            'barryvdh/laravel-ide-helper',
            'gocpa/laravel-request-time-logger',
            'gocpa/space-healthcheck',
            'gocpa/vulnerability-scanner-honeypot',
            'larastan/larastan',
            'laravel/framework',
            'laravel/horizon',
            'laravel/pail',
            'laravel/pint',
            'laravel/pulse',
            'laravel/telescope',
            'msamgan/laravel-env-keys-checker',
            'nunomaduro/larastan',
            'opcodesio/log-viewer',
            'sentry/sentry-laravel',
            'spatie/laravel-health',
            'tightenco/duster',
        ];

        $composerInfo = [];

        foreach ($packages as $package) {
            $composerInfo[$package] = $this->getInstalledVersion($package);
        }

        return $composerInfo;
    }

    private function getInstalledVersion(string $packageName): ?string
    {
        try {
            return InstalledVersions::getVersion($packageName);
        } catch (OutOfBoundsException) {
            return null;
        }
    }

    /** @return array<array-key, mixed>|null */
    private function getHealthData(): ?array
    {
        $latestResults = app(ResultStore::class)->latestResults();

        if (is_null($latestResults)) {
            return null;
        }

        $result = json_decode($latestResults->toJson(), true);

        return is_array($result) ? $result : null;
    }
}
