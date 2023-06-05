<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Http\Controllers;

use GoCPA\SpaceHealthcheck\Http\Middleware\EnsureSecretKeyIsValid;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OutOfBoundsException;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;

class SpaceHealthCheckController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function __construct(
        private ?ResultStore $resultStore = null,
    ) {
        $this->middleware(EnsureSecretKeyIsValid::class);
    }

    /**
     * Выводит результат для мониторинга
     */
    public function __invoke(): JsonResponse
    {
        $result = [];
        $result['generatedAt'] = time();
        $result['git'] = $this->getGitInfo();
        $result['composer'] = [
            'laravel/framework' => $this->getInstalledVersion('laravel/framework'),
            'spatie/laravel-health' => $this->getInstalledVersion('spatie/laravel-health'),
            'gocpa/space-healthcheck' => $this->getInstalledVersion('gocpa/space-healthcheck'),
        ];
        try {
            $result['health'] = $this->getHealthData();
        } catch (BindingResolutionException) {
        }

        return new JsonResponse($result);
    }

    private function getInstalledVersion(string $packageName): ?string
    {
        try {
            return \Composer\InstalledVersions::getVersion($packageName);
        } catch (OutOfBoundsException) {
            return null;
        }
    }

    private function getHealthData(): ?array
    {
        try {
            return [
                'finishedAt' => $this->resultStore->latestResults()?->finishedAt->getTimestamp(),
                'checkResults' => $this->resultStore->latestResults()?->storedCheckResults->map(fn (StoredCheckResult $line) => $line->toArray()),
            ];
        } catch (\Throwable $th) {
            return null;
        }
    }

    private function getGitInfo(): ?array
    {
        try {
            $gitBasePath = base_path() . '/.git';

            $gitStr = file_get_contents($gitBasePath . '/HEAD');
            $branchName = rtrim(preg_replace("/(.*?\/){2}/", '', $gitStr));
            $pathBranch = $gitBasePath . '/refs/heads/' . $branchName;
            $hash = trim(file_get_contents($pathBranch));
            $date = filemtime($pathBranch);

            return compact(
                'branchName',
                'hash',
                'date',
            );
        } catch (\Throwable $th) {
            return null;
        }
    }
}
