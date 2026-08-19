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

/** Тип объекта в packfile: 1 — commit, 6 — OFS_DELTA, 7 — REF_DELTA. */
function packedObjectType(string $repository, string $hash): int
{
    $index = glob($repository.'/.git/objects/pack/*.idx')[0];
    $data = (string) file_get_contents($index);

    $total = (int) unpack('N', substr($data, 8 + 255 * 4, 4))[1];
    $hashes = substr($data, 1032, $total * 20);
    $position = intdiv((int) strpos($hashes, (string) hex2bin($hash)), 20);

    $offsets = 1032 + $total * 20 + $total * 4;
    $offset = (int) unpack('N', substr($data, $offsets + $position * 4, 4))[1];

    $pack = (string) file_get_contents(substr($index, 0, -4).'.pack');

    return (ord($pack[$offset]) >> 4) & 0x07;
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

it('разворачивает дельта-коммиты из packfile', function (string $repack, int $expectedType) {
    // git хранит дельтами и коммиты: множество похожих сообщений это гарантирует.
    $message = str_repeat('Длинное однотипное сообщение коммита для дельтификации ', 6);
    for ($i = 1; $i <= 20; $i++) {
        git($this->repository, sprintf('commit --quiet --allow-empty -m %s', escapeshellarg($message.$i)));
    }
    git($this->repository, $repack);

    $index = glob($this->repository.'/.git/objects/pack/*.idx')[0] ?? '';
    $deltas = [];
    foreach (explode("\n", git($this->repository, 'verify-pack -v '.escapeshellarg($index))) as $line) {
        $columns = preg_split('/\s+/', trim($line)) ?: [];
        // У дельты в verify-pack есть два лишних столбца: глубина и база.
        if (count($columns) === 7 && $columns[1] === 'commit') {
            $deltas[] = $columns[0];
        }
    }

    expect($deltas)->not->toBeEmpty('фикстура должна содержать дельта-коммиты');

    $hash = $deltas[0];
    expect(packedObjectType($this->repository, $hash))->toBe($expectedType);

    git($this->repository, 'checkout --quiet --detach '.$hash);

    expect(readGit($this->repository))->toBe([
        'branchName' => null,
        'hash' => $hash,
        'date' => (int) git($this->repository, "log -1 --format='%ct' ".$hash),
    ]);
})->with([
    'OFS_DELTA' => ['repack -adf --depth=50 --window=250 -q', 6],
    'REF_DELTA' => ['-c repack.useDeltaBaseOffset=false repack -adf --depth=50 --window=250 -q', 7],
]);

it('читает объекты из alternates при clone --shared', function () {
    git($this->repository, 'gc --quiet --prune=now');
    $expected = git($this->repository, "log -1 --format='%H %ct'");
    [$hash, $timestamp] = explode(' ', $expected);

    $shared = $this->repository.'-shared';
    git($this->repository, 'clone --quiet --shared . '.escapeshellarg($shared));

    // Своего хранилища объектов у такого клона нет — только ссылка на чужое.
    expect(is_file($shared.'/.git/objects/info/alternates'))->toBeTrue();
    expect(glob($shared.'/.git/objects/pack/*.pack'))->toBe([]);

    expect(readGit($shared)['date'])->toBe((int) $timestamp);
    expect(readGit($shared)['hash'])->toBe($hash);

    removeDirectory($shared);
});

it('читает 8-байтные смещения из .idx', function () {
    // Большие смещения git пишет только для packfile тяжелее 2 ГБ, поэтому
    // собираем .idx с такой таблицей вручную.
    $hash = str_repeat('ab', 20);
    $timestamp = 1700000000;
    $commit = 'tree '.str_repeat('cd', 20)."\n"
        ."author Test <test@example.com> {$timestamp} +0000\n"
        ."committer Test <test@example.com> {$timestamp} +0000\n\nfixture\n";

    $packDirectory = $this->repository.'/.git/objects/pack';
    $packOffset = 12;

    // Заголовок объекта: тип 1 (commit) + размер в 7-битных группах.
    $size = strlen($commit);
    $header = chr(0x80 | (1 << 4) | ($size & 0x0F));
    $size >>= 4;
    while ($size > 0) {
        $header .= chr(($size > 0x7F ? 0x80 : 0) | ($size & 0x7F));
        $size >>= 7;
    }

    file_put_contents($packDirectory.'/pack-large.pack', "PACK\0\0\0\2\0\0\0\1".$header.gzcompress($commit));

    $binaryHash = hex2bin($hash);
    $fanout = '';
    for ($i = 0; $i < 256; $i++) {
        $fanout .= pack('N', $i < ord($binaryHash[0]) ? 0 : 1);
    }

    file_put_contents($packDirectory.'/pack-large.idx',
        "\xfftOc\0\0\0\2"
        .$fanout
        .$binaryHash                     // таблица хешей
        .pack('N', 0)                    // crc
        .pack('N', 0x80000000)           // смещение: старший бит — ссылка в таблицу больших
        .pack('J', $packOffset)          // таблица 8-байтных смещений
        .str_repeat("\0", 40)            // контрольные суммы, мы их не читаем
    );

    file_put_contents($this->repository.'/.git/HEAD', $hash."\n");

    expect(readGit($this->repository))->toBe([
        'branchName' => null,
        'hash' => $hash,
        'date' => $timestamp,
    ]);
});

it('бросает исключение, если репозитория нет', function () {
    $directory = sys_get_temp_dir().'/space-healthcheck-norepo-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    expect(fn () => readGit($directory))->toThrow(Exception::class, 'git not found');

    removeDirectory($directory);
});
