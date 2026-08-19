<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck;

use Exception;

class Git
{
    private const TYPE_COMMIT = 1;

    private const TYPE_OFS_DELTA = 6;

    private const TYPE_REF_DELTA = 7;

    /** Ограничитель рекурсии по цепочке дельт: у git глубина по умолчанию 50. */
    private const MAX_DELTA_DEPTH = 50;

    /** Каталог .git текущего рабочего дерева — здесь лежит HEAD. */
    private string $basePath;

    /**
     * Общий каталог репозитория — здесь лежат refs, packed-refs и objects.
     * Совпадает с basePath везде, кроме git worktree.
     */
    private string $commonPath;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->basePath = $this->getBasePath();
        $this->commonPath = $this->getCommonPath();
    }

    /**
     * @return array{"branchName": ?string, "hash": ?string, "date": ?int}
     */
    public function run(): array
    {
        [$branch, $hash] = $this->readHead();

        // В detached HEAD ветки нет, но хеш лежит прямо в HEAD.
        if ($hash === null && $branch !== null) {
            $hash = $this->resolveBranch($branch);
        }

        return [
            'branchName' => $branch,
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

            if (preg_match('/^ref:\s*refs\/heads\/(.+)$/m', $head, $matches) === 1) {
                return [trim($matches[1]), null];
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
     * Ищет хеш ветки сначала среди loose-ссылок, затем в packed-refs:
     * после git clone / git gc файла refs/heads/<branch> может не быть.
     */
    private function resolveBranch(string $branch): ?string
    {
        try {
            $branchFile = "{$this->commonPath}/refs/heads/{$branch}";
            if (is_file($branchFile)) {
                $hash = trim((string) file_get_contents($branchFile));
                if ($this->isCommitHash($hash)) {
                    return $hash;
                }
            }

            $packedRefs = "{$this->commonPath}/packed-refs";
            if (is_file($packedRefs)) {
                $content = @file_get_contents($packedRefs);
                $pattern = '/^([0-9a-f]{40,64})\s+'.preg_quote("refs/heads/{$branch}", '/').'$/m';
                if ($content !== false && preg_match($pattern, $content, $matches) === 1) {
                    return $matches[1];
                }
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }

    private function getCommitDate(string $commitHash): ?int
    {
        try {
            $rawCommit = $this->readLooseObject($commitHash) ?? $this->readPackedObject($commitHash);

            if ($rawCommit !== null && preg_match('/committer .*? (\d+) /', $rawCommit, $matches) === 1) {
                return (int) $matches[1];
            }
        } catch (\Throwable $e) {
            // Log error if necessary
        }

        return null;
    }

    private function readLooseObject(string $commitHash): ?string
    {
        $objectPath = "{$this->commonPath}/objects/".substr($commitHash, 0, 2).'/'.substr($commitHash, 2);
        if (! is_file($objectPath)) {
            return null;
        }

        $raw = @file_get_contents($objectPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = @zlib_decode($raw);

        return $decoded === false ? null : $decoded;
    }

    /**
     * После git clone и git gc все объекты лежат в packfile, loose-объектов нет вообще.
     * Разбираем .idx (версии 2), находим смещение и распаковываем объект из .pack,
     * разворачивая дельты (git хранит дельтами и часть коммитов).
     */
    private function readPackedObject(string $commitHash): ?string
    {
        $indexes = glob("{$this->commonPath}/objects/pack/*.idx");
        if ($indexes === false) {
            return null;
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

            if ($object !== null && $object['type'] === self::TYPE_COMMIT) {
                return $object['data'];
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
