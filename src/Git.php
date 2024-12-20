<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use Exception;

class Git
{
    private string $base_path;

    private string $head;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->base_path = $this->getBasePath();
    }

    public function run(): array
    {
        $branch = null;
        $commitHash = null;
        $tag = null;
        $commitDate = null;

        try {
            // Получение текущей ветки
            $headFile = file_get_contents($this->base_path . '/HEAD');
            preg_match('/ref: refs\/heads\/(.*)/', $headFile, $matches);
            $branch = $matches[1] ?? null;
        } catch (\Throwable) {
        }

        // Получение хеша последнего коммита
        try {
            if ($branch) {
                $branchFile = $this->base_path . '/refs/heads/' . $branch;
                if (file_exists($branchFile)) {
                    $commitHash = trim(file_get_contents($branchFile));
                }
            }
        } catch (\Throwable) {
        }

        // Получение последнего тега
        try {
            $tagsPath = $this->base_path . '/refs/tags';
            if (is_dir($tagsPath)) {
                $tags = array_diff(scandir($tagsPath), ['.', '..']);
                if (!empty($tags)) {
                    $tag = end($tags);
                }
            }
        } catch (\Throwable) {
        }

        // Получение даты последнего коммита
        try {
            if ($commitHash) {
                $objectsPath = $this->base_path . '/objects/' . substr($commitHash, 0, 2) . '/' . substr($commitHash, 2);
                if (file_exists($objectsPath)) {
                    $rawCommit = file_get_contents($objectsPath);
                    if ($rawCommit) {
                        $decodedCommit = zlib_decode($rawCommit);
                        if (preg_match('/committer .*? (\d+) /', $decodedCommit, $matches)) {
                            $commitDate = $matches[1] ?? -1;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return [
            'branchName' => $branch,
            'tag' => $tag,
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
