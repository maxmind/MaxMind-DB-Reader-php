<?php

declare(strict_types=1);

namespace MaxMind\Db\Test\Reader;

/**
 * Seekable test stream whose read callback can reenter a Reader.
 *
 * @internal
 */
class ReentrantStream
{
    /** @var resource|null */
    public $context;

    /** @var string */
    public static $data = '';

    /** @var callable|null */
    public static $onRead;

    /** @var int */
    private $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $offset = $this->position;
        $bytes = substr(self::$data, $this->position, $count);
        $this->position += \strlen($bytes);
        $callback = self::$onRead;
        if ($callback !== null) {
            $callback($offset);
        }

        return $bytes;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        if ($whence === \SEEK_SET) {
            $this->position = $offset;
        } elseif ($whence === \SEEK_CUR) {
            $this->position += $offset;
        } else {
            $this->position = \strlen(self::$data) + $offset;
        }

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \strlen(self::$data);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => \strlen(self::$data), 'mode' => 0100444];
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return $this->stream_stat();
    }
}
