<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use GoCPA\SpaceHealthcheck\Exceptions\GitNotFoundException;

class Git
{
    private string $base_path;

    private string $head;

    public function __construct()
    {
        if (! is_dir($basePath = base_path('.git/'))) {
            throw new GitNotFoundException('git not found');
        }

        $this->base_path = $basePath;
        $this->head = trim(substr(file_get_contents($this->base_path.'HEAD'), 4));
    }

    public function getBranchName(): string
    {
        return rtrim(preg_replace("/(.*?\/){2}/", '', $this->head));
    }

    public function getHash(): string
    {
        return trim(file_get_contents(sprintf($this->base_path.$this->head)));
    }

    public function getCommitDate($branchName): int|false
    {
        $pathBranch = sprintf('%s/refs/heads/%s', $this->base_path, $branchName);

        return filemtime($pathBranch);
    }
}
