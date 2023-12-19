<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use Illuminate\Contracts\Filesystem\FileNotFoundException;

class Git
{
    private string $gitDir = '.git';

    public function __construct(?string $repositoryPath = null)
    {
        if ($repositoryPath) {
            $this->gitDir = rtrim($repositoryPath, '/').'/.git';
        } else {
            $this->gitDir = base_path($this->gitDir);
        }
    }

    public function getBranchName(): ?string
    {
        $headContent = $this->getFile($this->gitDir.'/HEAD');
        if (preg_match('#ref: refs/heads/(.+)#', $headContent, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function getHash(): ?string
    {
        $branchName = $this->getBranchName();
        if ($branchName) {
            return trim($this->getFile($this->gitDir.'/refs/heads/'.$branchName));
        }

        return null;
    }

    public function getCommitDate(): ?int
    {
        try {
            $hash = $this->getHash();
            if (! $hash) {
                return null;
            }

            $pathBranch = $this->gitDir.'/refs/heads/'.$branchName;
            if (! is_file($pathBranch)) {
                return false;
            }

            return filemtime($pathBranch);

        } catch (\Throwable $th) {
            return null;
        }
    }

    public function getFile(string $file): string
    {
        if (! is_file($file)) {
            throw new FileNotFoundException($file);
        }

        return (string) file_get_contents($file);
    }
}
