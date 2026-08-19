<?php

declare(strict_types=1);

use GoCPA\SpaceHealthcheck\Git;

/**
 * Фикстуры создаются настоящим git — сам пакет читает .git/ файлами,
 * но для подготовки разных раскладок бинарник удобнее.
 */
function git(string $directory, string $command): string
{
    $output = shell_exec(sprintf('cd %s && git %s 2>&1', escapeshellarg($directory), $command));

    return is_string($output) ? trim($output) : '';
}

function makeRepository(): string
{
    $directory = sys_get_temp_dir().'/space-healthcheck-git-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    git($directory, 'init --quiet --initial-branch=main');
    git($directory, 'config user.email test@example.com');
    git($directory, 'config user.name Test');
    file_put_contents($directory.'/readme.md', 'test');
    git($directory, 'add .');
    git($directory, 'commit --quiet -m "Initial commit"');

    return $directory;
}

function removeDirectory(string $directory): void
{
    if (is_dir($directory)) {
        shell_exec('rm -rf '.escapeshellarg($directory));
    }
}

/** @return array{"branchName": ?string, "hash": ?string, "date": ?int} */
function readGit(string $directory): array
{
    app()->setBasePath($directory);

    return (new Git)->run();
}

beforeEach(function () {
    if (shell_exec('command -v git') === null) {
        $this->markTestSkipped('git не установлен');
    }

    $this->repository = makeRepository();
});

afterEach(function () {
    removeDirectory($this->repository);
});

it('читает loose-объекты', function () {
    $expected = git($this->repository, "log -1 --format='%H %ct'");
    [$hash, $timestamp] = explode(' ', $expected);

    expect(readGit($this->repository))->toBe([
        'branchName' => 'main',
        'hash' => $hash,
        'date' => (int) $timestamp,
    ]);
});

it('читает объекты из packfile после git gc', function () {
    $expected = git($this->repository, "log -1 --format='%H %ct'");
    [$hash, $timestamp] = explode(' ', $expected);

    // После gc loose-объектов не остаётся — ровно то, что даёт git clone на деплое.
    git($this->repository, 'gc --quiet --prune=now');
    $loose = glob($this->repository.'/.git/objects/[0-9a-f][0-9a-f]/*');

    expect($loose)->toBe([]);
    expect(readGit($this->repository))->toBe([
        'branchName' => 'main',
        'hash' => $hash,
        'date' => (int) $timestamp,
    ]);
});

it('читает ветку из packed-refs, когда loose-ссылки нет', function () {
    $hash = git($this->repository, "log -1 --format='%H'");

    git($this->repository, 'pack-refs --all');
    @unlink($this->repository.'/.git/refs/heads/main');

    expect(is_file($this->repository.'/.git/refs/heads/main'))->toBeFalse();
    expect(readGit($this->repository)['hash'])->toBe($hash);
});

it('отдаёт хеш в detached HEAD', function () {
    $hash = git($this->repository, "log -1 --format='%H'");
    git($this->repository, 'checkout --quiet --detach HEAD');

    $result = readGit($this->repository);

    expect($result['branchName'])->toBeNull();
    expect($result['hash'])->toBe($hash);
    expect($result['date'])->toBeInt();
});

it('работает в git worktree, где .git — файл', function () {
    $hash = git($this->repository, "log -1 --format='%H'");
    $worktree = $this->repository.'-worktree';
    git($this->repository, 'worktree add --quiet -b worktree-branch '.escapeshellarg($worktree));

    expect(is_file($worktree.'/.git'))->toBeTrue();
    expect(readGit($worktree))->toBe([
        'branchName' => 'worktree-branch',
        'hash' => $hash,
        'date' => (int) git($this->repository, "log -1 --format='%ct'"),
    ]);

    removeDirectory($worktree);
});

it('бросает исключение, если репозитория нет', function () {
    $directory = sys_get_temp_dir().'/space-healthcheck-norepo-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    expect(fn () => readGit($directory))->toThrow(Exception::class, 'git not found');

    removeDirectory($directory);
});
