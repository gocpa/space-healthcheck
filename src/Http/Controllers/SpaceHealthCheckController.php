<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Http\Controllers;

use GoCPA\SpaceHealthcheck\Git;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OutOfBoundsException;

class SpaceHealthCheckController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Выводит результат для мониторинга
     */
    public function __invoke(): JsonResponse
    {
        $result = [];
        $result['generatedAt'] = now()->timestamp;
        $result['git'] = $this->getGitInfo();
        $result['composer'] = $this->getComposerInfo();
        $result['health'] = $this->getHealthData();
        $result['environment'] = config('app.env');
        $result['name'] = config('app.name');
        $result['env'] = config('app.env');
        $result['debug'] = config('app.debug');

        return new JsonResponse($result);
    }

    /** @return array<string,string|null> */
    private function getGitInfo(): array
    {
        return app(Git::class)->run();
    }

    /** @return array<string,string|null> */
    private function getComposerInfo(): array
    {
        $packages = [
            'barryvdh/laravel-debugbar',
            'barryvdh/laravel-ide-helper',
            'gocpa/space-healthcheck',
            'gocpa/vulnerability-scanner-honeypot',
            'laravel/framework',
            'laravel/horizon',
            'laravel/pint',
            'laravel/pulse',
            'laravel/telescope',
            'spatie/laravel-health',
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
            return \Composer\InstalledVersions::getVersion($packageName);
        } catch (OutOfBoundsException) {
            return null;
        }
    }

    /** @return array<string,int|null> */
    private function getHealthData(): ?array
    {
        if (class_exists('\Spatie\Health\ResultStores\ResultStore') === false) {
            return null;
        }

        if (class_exists('\Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults') === false) {
            return null;
        }

        /** @var \Spatie\Health\ResultStores\ResultStore $resultStore */
        $resultStore = app('Spatie\Health\ResultStores\ResultStore');


        /** @var ?\Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults $latestResults */
        $latestResults = $resultStore->latestResults();

        if (is_null($latestResults)) {
            return null;
        }

        $json = $latestResults->toJson();

        $result = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $result;
    }
}
