<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use Exception;

class Git
{
    private string $base_path;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->base_path = $this->getBasePath();
    }

    /**
     * @return array{"branchName": ?string, "hash": ?string, "date": ?numeric-string}
     */
    public function run(): array
    {
        $branch = null;
        $commitHash = null;
        $commitDate = null;

        try {
            // Получение текущей ветки
            $headFile = file_get_contents($this->base_path . '/HEAD');
            if ($headFile) {
                preg_match('/ref: refs\/heads\/(.*)/', $headFile, $matches);
                $branch = $matches[1] ?? null;
            }
        } catch (\Throwable) {
        }

        // Получение хеша последнего коммита
        try {
            if ($branch) {
                $branchFile = $this->base_path.'/refs/heads/'.$branch;
                if (file_exists($branchFile)) {
                    /** @var string $branchFileContent */
                    $branchFileContent = file_get_contents($branchFile);
                    $commitHash = trim($branchFileContent);
                }
            }
        } catch (\Throwable) {
        }

        // Получение даты последнего коммита
        try {
            if ($commitHash) {
                $objectsPath = $this->base_path.'/objects/'.substr($commitHash, 0, 2).'/'.substr($commitHash, 2);
                if (file_exists($objectsPath)) {
                    $rawCommit = file_get_contents($objectsPath);
                    if ($rawCommit) {
                        $decodedCommit = zlib_decode($rawCommit);
                        if ($decodedCommit) {
                            if (preg_match('/committer .*? (\d+) /', $decodedCommit, $matches)) {
                                $commitDate = $matches[1];
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return [
            'branchName' => $branch,
            'hash' => $commitHash,
            'date' => $commitDate,
        ];
    }

    /**
     * @throws Exception
     */
    private function getBasePath(): string
    {
        if (! is_dir($basePath = base_path('.git/'))) {
            throw new Exception('git not found');
        }

        return rtrim($basePath, '/');
    }
}
