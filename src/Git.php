<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use Exception;

class Git
{
    private const TYPE_COMMIT = 1;

    private const TYPE_OFS_DELTA = 6;

    private const TYPE_REF_DELTA = 7;

    private const TYPE_TAG = 4;

    /** Ограничитель разворачивания цепочки тегов (тег может указывать на тег). */
    private const MAX_TAG_DEPTH = 5;

    /** Ограничитель рекурсии по цепочке дельт: у git глубина по умолчанию 50. */
    private const MAX_DELTA_DEPTH = 50;

    /** Ограничитель обхода цепочки alternates. */
    private const MAX_OBJECT_DIRECTORIES = 10;

    /** Каталог .git текущего рабочего дерева — здесь лежит HEAD. */
    private string $basePath;

    /**
     * Общий каталог репозитория — здесь лежат refs, packed-refs и objects.
     * Совпадает с basePath везде, кроме git worktree.
     */
    private string $commonPath;

    /**
     * Каталоги с объектами: свой плюс подключённые через objects/info/alternates.
     *
     * @var list<string>|null
     */
    private ?array $objectDirectories = null;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->basePath = $this->getBasePath();
        $this->commonPath = $this->getCommonPath();
    }

    /**
     * @return array{"branchName": ?string, "tag": ?string, "hash": ?string, "date": ?int}
     */
    public function run(): array
    {
        [$branch, $hash] = $this->readHead();

        return [
            'branchName' => $branch,
            'tag' => $hash !== null ? $this->findTag($hash) : null,
            'hash' => $hash,
            'date' => $hash !== null ? $this->getCommitDate($hash) : null,
        ];
    }

    /**
     * @throws Exception
     */
    private function getBasePath(): string
    {
        $path = rtrim(base_path('.git'), '/');

        if (is_dir($path)) {
            return $path;
        }

        // В git worktree и в submodule .git — файл вида "gitdir: <путь>".
        if (is_file($path)) {
            $content = @file_get_contents($path);
            if ($content !== false && preg_match('/^gitdir:\s*(.+)$/m', $content, $matches) === 1) {
                $gitDir = $this->absolutePath(trim($matches[1]), dirname($path));
                if (is_dir($gitDir)) {
                    return $gitDir;
                }
            }
        }

        throw new Exception('git not found');
    }

    /**
     * В worktree HEAD лежит в .git/worktrees/<name>/, а refs и objects — в общем каталоге,
     * путь к которому записан в файле commondir.
     */
    private function getCommonPath(): string
    {
        $commonDirFile = "{$this->basePath}/commondir";
        if (is_file($commonDirFile)) {
            $content = @file_get_contents($commonDirFile);
            if ($content !== false && trim($content) !== '') {
                $commonPath = $this->absolutePath(trim($content), $this->basePath);
                if (is_dir($commonPath)) {
                    return $commonPath;
                }
            }
        }

        return $this->basePath;
    }

    private function absolutePath(string $path, string $relativeTo): string
    {
        if (! str_starts_with($path, '/')) {
            $path = rtrim($relativeTo, '/').'/'.$path;
        }

        return rtrim($path, '/');
    }

    /**
     * Читает HEAD: либо ссылку на ветку, либо готовый хеш (detached HEAD).
     *
     * @return array{0: ?string, 1: ?string} [branch, hash]
     */
    private function readHead(): array
    {
        try {
            $head = @file_get_contents("{$this->basePath}/HEAD");
            if ($head === false) {
                return [null, null];
            }

            $head = trim($head);

            // В HEAD может стоять ссылка на что угодно из refs/, не только на ветку.
            if (preg_match('/^ref:\s*(\S+)$/m', $head, $matches) === 1) {
                $ref = $matches[1];
                $branch = str_starts_with($ref, 'refs/heads/')
                    ? substr($ref, strlen('refs/heads/'))
                    : null;

                return [$branch, $this->resolveRef($ref)];
            }

            if ($this->isCommitHash($head)) {
                return [null, $head];
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return [null, null];
    }

    /**
     * Ищет хеш ссылки сначала среди loose-файлов, затем в packed-refs:
     * после git clone / git gc файла refs/heads/<branch> может не быть.
     * Аннотированный тег разворачивается до коммита.
     */
    private function resolveRef(string $ref): ?string
    {
        try {
            $refFile = "{$this->commonPath}/{$ref}";
            if (is_file($refFile)) {
                $hash = trim((string) file_get_contents($refFile));
                if ($this->isCommitHash($hash)) {
                    return $this->peel($hash);
                }
            }

            $packedRefs = @file_get_contents("{$this->commonPath}/packed-refs");
            $pattern = '/^([0-9a-f]{40,64})\s+'.preg_quote($ref, '/').'$/m';
            if ($packedRefs !== false && preg_match($pattern, $packedRefs, $matches) === 1) {
                return $this->peel($matches[1]);
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }

    /**
     * Аннотированный тег — отдельный объект, который ссылается на коммит
     * полем object. Разворачиваем цепочку до самого коммита.
     */
    private function peel(string $hash, int $depth = 0): string
    {
        if ($depth >= self::MAX_TAG_DEPTH) {
            return $hash;
        }

        try {
            $object = $this->readObject($hash);
        } catch (\Throwable $e) {
            return $hash;
        }

        if ($object === null || $object['type'] !== self::TYPE_TAG) {
            return $hash;
        }

        if (preg_match('/^object ([0-9a-f]{40,64})$/m', $object['data'], $matches) === 1) {
            return $this->peel($matches[1], $depth + 1);
        }

        return $hash;
    }

    /**
     * Имя тега, указывающего на коммит. Лёгкие теги ссылаются на коммит напрямую,
     * аннотированные — через tag-объект; в packed-refs развёрнутое значение лежит
     * следующей строкой после ^. Если тегов несколько, берётся последний в
     * натуральной сортировке — для vX.Y.Z это самая свежая версия.
     */
    private function findTag(string $commitHash): ?string
    {
        try {
            $names = array_merge(
                $this->findPackedTags($commitHash),
                $this->findLooseTags($commitHash)
            );

            if ($names === []) {
                return null;
            }

            usort($names, 'strnatcmp');

            return end($names);
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }

    /** @return list<string> */
    private function findPackedTags(string $commitHash): array
    {
        $packedRefs = @file_get_contents("{$this->commonPath}/packed-refs");
        if ($packedRefs === false) {
            return [];
        }

        $names = [];
        $pending = null;

        foreach (preg_split('/\R/', $packedRefs) ?: [] as $line) {
            // Строка ^<hash> — развёрнутое значение тега из предыдущей строки.
            if (str_starts_with($line, '^')) {
                if ($pending !== null && substr($line, 1) === $commitHash) {
                    $names[] = $pending;
                }
                $pending = null;

                continue;
            }

            $pending = null;
            if (preg_match('/^([0-9a-f]{40,64})\s+refs\/tags\/(.+)$/', $line, $matches) === 1) {
                if ($matches[1] === $commitHash) {
                    $names[] = $matches[2];
                }
                $pending = $matches[2];
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function findLooseTags(string $commitHash, string $prefix = ''): array
    {
        $directory = rtrim("{$this->commonPath}/refs/tags/{$prefix}", '/');
        $entries = @scandir($directory);
        if ($entries === false) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $name = $prefix === '' ? $entry : "{$prefix}/{$entry}";

            // Теги могут лежать во вложенных каталогах: refs/tags/release/v1.
            if (is_dir("{$directory}/{$entry}")) {
                $names = array_merge($names, $this->findLooseTags($commitHash, $name));

                continue;
            }

            $hash = trim((string) @file_get_contents("{$directory}/{$entry}"));
            if ($this->isCommitHash($hash) && $this->peel($hash) === $commitHash) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function getCommitDate(string $commitHash): ?int
    {
        try {
            $object = $this->readObject($commitHash);

            if ($object !== null
                && $object['type'] === self::TYPE_COMMIT
                && preg_match('/committer .*? (\d+) /', $object['data'], $matches) === 1
            ) {
                return (int) $matches[1];
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }

    /** @return array{type: int, data: string}|null */
    private function readObject(string $hash): ?array
    {
        return $this->readLooseObject($hash) ?? $this->readPackedObject($hash);
    }

    /**
     * Распаковка нужна только для чтения объектов. Ветка, хеш и теги из
     * packed-refs читаются и без zlib, поэтому отсутствие расширения гасит
     * только дату коммита, а не всю секцию.
     */
    private function decompress(string $raw): ?string
    {
        if (! function_exists('zlib_decode')) {
            return null;
        }

        $decoded = @zlib_decode($raw);

        return $decoded === false ? null : $decoded;
    }

    /** @return array{type: int, data: string}|null */
    private function readLooseObject(string $hash): ?array
    {
        $suffix = '/'.substr($hash, 0, 2).'/'.substr($hash, 2);

        foreach ($this->objectDirectories() as $directory) {
            if (! is_file($directory.$suffix)) {
                continue;
            }

            $raw = @file_get_contents($directory.$suffix);
            if ($raw === false || $raw === '') {
                continue;
            }

            // Loose-объект: "<тип> <размер>\0<содержимое>".
            $decoded = $this->decompress($raw);
            $separator = $decoded === null ? false : strpos($decoded, "\0");
            if ($decoded === null || $separator === false) {
                continue;
            }

            $type = match (explode(' ', substr($decoded, 0, $separator))[0]) {
                'commit' => self::TYPE_COMMIT,
                'tag' => self::TYPE_TAG,
                default => 0,
            };

            return ['type' => $type, 'data' => substr($decoded, $separator + 1)];
        }

        return null;
    }

    /**
     * При git clone --shared и --reference своего хранилища объектов нет вообще:
     * пути к чужим лежат в objects/info/alternates и могут быть вложенными.
     *
     * @return list<string>
     */
    private function objectDirectories(): array
    {
        if ($this->objectDirectories !== null) {
            return $this->objectDirectories;
        }

        $directories = [];
        $queue = ["{$this->commonPath}/objects"];

        while ($queue !== [] && count($directories) < self::MAX_OBJECT_DIRECTORIES) {
            $directory = rtrim((string) array_shift($queue), '/');

            if ($directory === '' || in_array($directory, $directories, true) || ! is_dir($directory)) {
                continue;
            }

            $directories[] = $directory;

            $alternates = @file_get_contents($directory.'/info/alternates');
            if ($alternates === false) {
                continue;
            }

            foreach (preg_split('/\R/', $alternates) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $queue[] = $this->absolutePath($line, $directory);
                }
            }
        }

        return $this->objectDirectories = $directories;
    }

    /**
     * После git clone и git gc все объекты лежат в packfile, loose-объектов нет вообще.
     * Разбираем .idx (версии 2), находим смещение и распаковываем объект из .pack,
     * разворачивая дельты (git хранит дельтами и часть коммитов).
     */
    /** @return array{type: int, data: string}|null */
    private function readPackedObject(string $commitHash): ?array
    {
        $indexes = [];
        foreach ($this->objectDirectories() as $directory) {
            $indexes = array_merge($indexes, glob($directory.'/pack/*.idx') ?: []);
        }

        $hashLength = intdiv(strlen($commitHash), 2);

        foreach ($indexes as $index) {
            $offset = $this->findOffsetInIndex($index, $commitHash);
            if ($offset === null) {
                continue;
            }

            $handle = @fopen(substr($index, 0, -4).'.pack', 'rb');
            if ($handle === false) {
                continue;
            }

            try {
                $object = $this->readObjectAt($handle, $index, $offset, $hashLength, 0);
            } finally {
                fclose($handle);
            }

            if ($object !== null) {
                return $object;
            }
        }

        return null;
    }

    private function findOffsetInIndex(string $index, string $commitHash): ?int
    {
        $handle = @fopen($index, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            // Только формат v2: магия \377tOc + версия 2.
            if (fread($handle, 8) !== "\xfftOc\x00\x00\x00\x02") {
                return null;
            }

            $binaryHash = @hex2bin($commitHash);
            if ($binaryHash === false) {
                return null;
            }

            $hashLength = strlen($binaryHash);
            if ($hashLength < 20) {
                return null;
            }

            $bucket = ord($binaryHash[0]);

            // Таблица fanout: 256 счётчиков по 4 байта.
            $from = $bucket === 0 ? 0 : $this->readUint32($handle, 8 + ($bucket - 1) * 4);
            $to = $this->readUint32($handle, 8 + $bucket * 4);
            $total = $this->readUint32($handle, 8 + 255 * 4);
            if ($from === null || $to === null || $total === null) {
                return null;
            }

            $hashTable = 8 + 256 * 4;
            $position = null;
            for ($i = $from; $i < $to; $i++) {
                if (fseek($handle, $hashTable + $i * $hashLength) !== 0) {
                    return null;
                }
                if (fread($handle, $hashLength) === $binaryHash) {
                    $position = $i;
                    break;
                }
            }

            if ($position === null) {
                return null;
            }

            // За таблицей хешей идут CRC (4 байта) и смещения (4 байта) на объект.
            $offsets = $hashTable + $total * $hashLength + $total * 4;
            $offset = $this->readUint32($handle, $offsets + $position * 4);
            if ($offset === null) {
                return null;
            }

            // Старший бит — признак 8-байтного смещения в следующей таблице.
            if (($offset & 0x80000000) !== 0) {
                $large = $offsets + $total * 4 + ($offset & 0x7FFFFFFF) * 8;
                if (fseek($handle, $large) !== 0) {
                    return null;
                }
                $bytes = fread($handle, 8);
                if ($bytes === false || strlen($bytes) !== 8) {
                    return null;
                }
                $unpacked = unpack('J', $bytes);

                return is_array($unpacked) && is_int($unpacked[1]) ? $unpacked[1] : null;
            }

            return $offset;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readUint32($handle, int $position): ?int
    {
        if (fseek($handle, $position) !== 0) {
            return null;
        }

        $bytes = fread($handle, 4);
        if ($bytes === false || strlen($bytes) !== 4) {
            return null;
        }

        $unpacked = unpack('N', $bytes);

        return is_array($unpacked) && is_int($unpacked[1]) ? $unpacked[1] : null;
    }

    /**
     * Читает объект по смещению в .pack. Для дельт (OFS_DELTA / REF_DELTA)
     * рекурсивно достаёт базовый объект и накладывает на него дельту.
     *
     * @param  resource  $handle
     * @return array{type: int, data: string}|null
     */
    private function readObjectAt($handle, string $index, int $offset, int $hashLength, int $depth): ?array
    {
        if ($depth > self::MAX_DELTA_DEPTH || fseek($handle, $offset) !== 0) {
            return null;
        }

        $header = fread($handle, 64);
        if ($header === false || $header === '') {
            return null;
        }

        // Заголовок: [1 бит продолжения][3 бита типа][4 бита размера], далее по 7 бит размера.
        $byte = ord($header[0]);
        $type = ($byte >> 4) & 0x07;
        $size = $byte & 0x0F;
        $shift = 4;
        $consumed = 1;
        while (($byte & 0x80) !== 0 && $consumed < strlen($header)) {
            $byte = ord($header[$consumed]);
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
            $consumed++;
        }

        if ($type === self::TYPE_OFS_DELTA) {
            $baseOffset = $this->readNegativeOffset($header, $consumed);
            if ($baseOffset === null || $baseOffset >= $offset) {
                return null;
            }

            return $this->applyDeltaTo(
                $this->readObjectAt($handle, $index, $offset - $baseOffset, $hashLength, $depth + 1),
                $this->inflateAt($handle, $offset + $consumed, $size)
            );
        }

        if ($type === self::TYPE_REF_DELTA) {
            $baseHash = bin2hex(substr($header, $consumed, $hashLength));
            $consumed += $hashLength;
            $baseOffset = $this->findOffsetInIndex($index, $baseHash);
            if ($baseOffset === null) {
                return null;
            }

            return $this->applyDeltaTo(
                $this->readObjectAt($handle, $index, $baseOffset, $hashLength, $depth + 1),
                $this->inflateAt($handle, $offset + $consumed, $size)
            );
        }

        $data = $this->inflateAt($handle, $offset + $consumed, $size);

        return $data === null ? null : ['type' => $type, 'data' => $data];
    }

    /**
     * Смещение базы в OFS_DELTA кодируется иначе, чем размер: каждый следующий
     * байт не дописывает биты, а сдвигает уже накопленное значение.
     */
    private function readNegativeOffset(string $header, int &$position): ?int
    {
        if ($position >= strlen($header)) {
            return null;
        }

        $byte = ord($header[$position]);
        $position++;
        $offset = $byte & 0x7F;

        while (($byte & 0x80) !== 0) {
            if ($position >= strlen($header)) {
                return null;
            }
            $byte = ord($header[$position]);
            $position++;
            $offset = (($offset + 1) << 7) | ($byte & 0x7F);
        }

        return $offset;
    }

    /**
     * @param  array{type: int, data: string}|null  $base
     * @return array{type: int, data: string}|null
     */
    private function applyDeltaTo(?array $base, ?string $delta): ?array
    {
        if ($base === null || $delta === null) {
            return null;
        }

        $data = $this->applyDelta($base['data'], $delta);

        // Тип наследуется от базы: дельта его не меняет.
        return $data === null ? null : ['type' => $base['type'], 'data' => $data];
    }

    /**
     * Формат дельты: размер базы, размер результата, затем инструкции —
     * копирование куска базы или вставка литерала.
     */
    private function applyDelta(string $base, string $delta): ?string
    {
        $position = 0;
        $baseSize = $this->readDeltaSize($delta, $position);
        $resultSize = $this->readDeltaSize($delta, $position);

        if ($baseSize !== strlen($base) || $resultSize === null) {
            return null;
        }

        $length = strlen($delta);
        $result = '';

        while ($position < $length) {
            $opcode = ord($delta[$position]);
            $position++;

            if (($opcode & 0x80) !== 0) {
                $copyOffset = 0;
                $copySize = 0;

                for ($i = 0; $i < 4; $i++) {
                    if (($opcode & (1 << $i)) !== 0) {
                        if ($position >= $length) {
                            return null;
                        }
                        $copyOffset |= ord($delta[$position]) << ($i * 8);
                        $position++;
                    }
                }

                for ($i = 0; $i < 3; $i++) {
                    if (($opcode & (1 << (4 + $i))) !== 0) {
                        if ($position >= $length) {
                            return null;
                        }
                        $copySize |= ord($delta[$position]) << ($i * 8);
                        $position++;
                    }
                }

                if ($copySize === 0) {
                    $copySize = 0x10000;
                }

                $result .= substr($base, $copyOffset, $copySize);

                continue;
            }

            if ($opcode === 0) {
                return null;
            }

            $result .= substr($delta, $position, $opcode);
            $position += $opcode;
        }

        return strlen($result) === $resultSize ? $result : null;
    }

    private function readDeltaSize(string $delta, int &$position): ?int
    {
        $size = 0;
        $shift = 0;
        $length = strlen($delta);

        do {
            if ($position >= $length) {
                return null;
            }
            $byte = ord($delta[$position]);
            $position++;
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $size;
    }

    /**
     * @param  resource  $handle
     */
    private function inflateAt($handle, int $position, int $size): ?string
    {
        if (fseek($handle, $position) !== 0) {
            return null;
        }

        if (! function_exists('inflate_init')) {
            return null;
        }

        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($inflate === false) {
            return null;
        }

        $result = '';
        while (strlen($result) < $size && ! feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $piece = @inflate_add($inflate, $chunk);
            if ($piece === false) {
                return null;
            }
            $result .= $piece;
        }

        return $result === '' ? null : $result;
    }

    private function isCommitHash(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40,64}$/', $value) === 1;
    }
}
