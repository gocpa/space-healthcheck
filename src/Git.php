<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use GoCPA\SpaceHealthcheck\Exceptions\GitNotFoundException;

class Git
{
    private string $base_path;

    private string $head;

    /**
     * @throws GitNotFoundException
     */
    public function __construct()
    {
        $this->base_path = $this->getBasePath();
        $this->head = $this->getHeadFileContents();
    }

    public function getBranchName(): string
    {
        return rtrim((string) preg_replace("/(.*?\/){2}/", '', $this->head));
    }

    public function getHash(): string
    {
        return trim((string) file_get_contents(sprintf($this->base_path.$this->head)));
    }

    public function getCommitDate(string $branchName): int|false
    {
        $pathBranch = sprintf('%s/refs/heads/%s', $this->base_path, $branchName);
        if (! is_file($pathBranch)) {
            return false;
        }

        return filemtime($pathBranch);
    }

    /**
     * @throws GitNotFoundException
     */
    private function getBasePath(): string
    {
        if (! is_dir($basePath = base_path('.git/'))) {
            throw new GitNotFoundException('git not found');
        }

        return $basePath;
    }

    private function getHeadFileContents(): string
    {
        return trim(substr((string) file_get_contents($this->base_path.'HEAD'), 4));
    }
}
