<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Http\Controllers;

use GoCPA\SpaceHealthcheck\Exceptions\GitNotFoundException;
use GoCPA\SpaceHealthcheck\Git;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OutOfBoundsException;
use Throwable;

class SpaceHealthCheckController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Выводит результат для мониторинга
     */
    public function __invoke(Git $git): JsonResponse
    {
        $result = [];
        $result['generatedAt'] = now()->timestamp;
        $result['git'] = $this->getGitInfo($git);
        $result['composer'] = $this->getComposerInfo();
        $result['health'] = $this->getHealthData();

        return new JsonResponse($result);
    }

    private function getGitInfo(Git $git): ?array
    {
        try {
            $branchName = $git->getBranchName();
            $hash = $git->getHash();
            $commitDate = $git->getCommitDate($branchName);

            return [
                'branchName' => $branchName,
                'hash' => $hash,
                'date' => $commitDate,
            ];
        } catch (GitNotFoundException|Throwable) {
            return null;
        }
    }

    private function getComposerInfo(): array
    {
        return [
            'laravel/framework' => $this->getInstalledVersion('laravel/framework'),
            'barryvdh/laravel-debugbar' => $this->getInstalledVersion('barryvdh/laravel-debugbar'),
            'spatie/laravel-health' => $this->getInstalledVersion('spatie/laravel-health'),
            'gocpa/space-healthcheck' => $this->getInstalledVersion('gocpa/space-healthcheck'),
            'gocpa/vulnerability-scanner-honeypot' => $this->getInstalledVersion('gocpa/vulnerability-scanner-honeypot'),
        ];
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
        if (! app()->bound('Spatie\Health\ResultStores\ResultStore')) {
            return null;
        }

        /** @var \Spatie\Health\ResultStores\ResultStore */ // @phpstan-ignore-next-line
        $resultStore = app()->get('Spatie\Health\ResultStores\ResultStore');

        return [
            // @phpstan-ignore-next-line
            'finishedAt' => $resultStore->latestResults()?->finishedAt->getTimestamp(),
            // @phpstan-ignore-next-line
            'checkResults' => $resultStore->latestResults()?->storedCheckResults->map(fn ($line) => $line->toArray()),
        ];
    }
}
