<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use Exception;

class Git
{
    private string $basePath;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->basePath = $this->getBasePath();
    }

    /**
     * @return array{"branchName": ?string, "hash": ?string, "date": ?int}
     */
    public function run(): array
    {
        return [
            'branchName' => $branch = $this->getCurrentBranch(),
            'hash' => $commitHash = $this->getLatestCommitHash($branch),
            'date' => $commitHash ? $this->getCommitDate($commitHash) : null,
        ];
    }

    /**
     * @throws Exception
     */
    private function getBasePath(): string
    {
        $basePath = base_path('.git/');
        if (! is_dir($basePath)) {
            throw new Exception('git not found');
        }

        return rtrim($basePath, '/');
    }

    private function getCurrentBranch(): ?string
    {
        try {
            $headFileContent = @file_get_contents("{$this->basePath}/HEAD");
            if ($headFileContent && preg_match('/ref: refs\/heads\/(.*)/', $headFileContent, $matches)) {
                return $matches[1];
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }

    private function getLatestCommitHash(?string $branch): ?string
    {
        if (! $branch) {
            return null;
        }

        try {
            $branchFile = "{$this->basePath}/refs/heads/{$branch}";
            if (file_exists($branchFile)) {
                return trim((string) file_get_contents($branchFile)) ?: null;
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }

    private function getCommitDate(string $commitHash): ?int
    {
        try {
            $objectsPath = "{$this->basePath}/objects/".substr($commitHash, 0, 2).'/'.substr($commitHash, 2);
            if (file_exists($objectsPath)) {
                $rawCommit = file_get_contents($objectsPath);
                if ($rawCommit) {
                    $decodedCommit = zlib_decode($rawCommit);
                    if ($decodedCommit && preg_match('/committer .*? (\d+) /', $decodedCommit, $matches)) {
                        return (int) $matches[1];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }
}
