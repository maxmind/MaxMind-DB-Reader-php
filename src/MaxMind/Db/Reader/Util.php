<?php

declare(strict_types=1);

namespace MaxMind\Db\Reader;

class Util
{
    /**
     * @param resource    $stream
     * @param int<0, max> $numberOfBytes
     */
    public static function read($stream, int $offset, int $numberOfBytes): string
    {
        if ($numberOfBytes === 0) {
            return '';
        }
        if (fseek($stream, $offset) === 0) {
            $value = fread($stream, $numberOfBytes);

            // Check that the number of bytes read is the number asked for.
            if ($value !== false && \strlen($value) === $numberOfBytes) {
                return $value;
            }
        }

        throw new InvalidDatabaseException(
            'The MaxMind DB file contains bad data'
        );
    }
}
