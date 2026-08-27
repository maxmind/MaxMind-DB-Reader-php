<?php

declare(strict_types=1);

namespace MaxMind\Db\Reader;

// @codingStandardsIgnoreLine

class Decoder
{
    /**
     * @var resource
     */
    private $fileStream;

    /**
     * @var int
     */
    private $pointerBase;

    /**
     * This is only used for unit testing.
     *
     * @var bool
     */
    private $pointerTestHack;

    /**
     * @var bool
     */
    private $switchByteOrder;

    private const _EXTENDED = 0;
    private const _POINTER = 1;
    private const _UTF8_STRING = 2;
    private const _DOUBLE = 3;
    private const _BYTES = 4;
    private const _UINT16 = 5;
    private const _UINT32 = 6;
    private const _MAP = 7;
    private const _INT32 = 8;
    private const _UINT64 = 9;
    private const _UINT128 = 10;
    private const _ARRAY = 11;
    // 12 is the container type
    // 13 is the end marker type
    private const _BOOLEAN = 14;
    private const _FLOAT = 15;

    // Per-lookup decode limits recommended by the MaxMind DB specification. The
    // depth limit stops pointer cycles and over-deep data. The value limit
    // stops a pointer fan-out, where nested pointers to shared targets would
    // otherwise cost 2**depth decode operations. The count follows the
    // specification's flat rule: the root is one value, each array and map
    // charges its declared children (a map entry costs two, key and value),
    // and a pointer costs nothing beyond the value it resolves to, which its
    // container already charged. The largest real records decode a few hundred
    // values, so the limit leaves a wide margin.
    private const MAX_DEPTH = 512;
    private const MAX_VALUES = 1 << 16;

    // The value limit alone does not stop payload amplification: an array of
    // pointers to one large string or bytes value keeps the value count low
    // while forcing the reader to copy the target once per pointer. This
    // second, independent limit bounds the total string and bytes payload
    // copied for one lookup to 2 MiB, matching libmaxminddb and the Go reader.
    // No real record approaches it, and re-decoding a shared target charges its
    // payload again, so the fan-out is bounded.
    private const MAX_PAYLOAD_BYTES = 1 << 21;

    // A fixed-width scalar (a float, double, or integer) never needs more than
    // 16 bytes (the width of a uint128). A larger declared size is either
    // corrupt or an attempt to amplify the read of an oversized variable-length
    // integer, so it is rejected before the bytes are materialized.
    private const MAX_SCALAR_BYTES = 16;

    /**
     * @param resource $fileStream
     */
    public function __construct(
        $fileStream,
        int $pointerBase = 0,
        bool $pointerTestHack = false
    ) {
        $this->fileStream = $fileStream;
        $this->pointerBase = $pointerBase;

        $this->pointerTestHack = $pointerTestHack;

        $this->switchByteOrder = $this->isPlatformLittleEndian();
    }

    /**
     * @return array<mixed>
     */
    public function decode(int $offset): array
    {
        // Bound the work per lookup so a crafted database cannot exhaust CPU or
        // memory. The two budgets are passed by reference so the running totals
        // are shared across the recursion. $budget counts decoded values and
        // stops the pointer fan-out; $byteBudget counts copied string and bytes
        // payload and stops payload amplification. Both are call-local, so
        // concurrent lookups do not share state. The root value is charged
        // here; containers charge their children.
        $budget = self::MAX_VALUES - 1;
        $byteBudget = self::MAX_PAYLOAD_BYTES;

        return $this->decodeWithBudget($offset, 0, $budget, $byteBudget);
    }

    /**
     * @return array<mixed>
     */
    private function decodeWithBudget(int $offset, int $depth, int &$budget, int &$byteBudget): array
    {
        $ctrlByte = \ord(Util::read($this->fileStream, $offset, 1));
        ++$offset;

        $type = $ctrlByte >> 5;

        // Pointers are a special case, we don't read the next $size bytes, we
        // use the size to determine the length of the pointer and then follow
        // it.
        if ($type === self::_POINTER) {
            [$pointer, $offset] = $this->decodePointer($ctrlByte, $offset);

            // for unit testing
            if ($this->pointerTestHack) {
                return [$pointer];
            }

            if ($depth >= self::MAX_DEPTH) {
                throw new InvalidDatabaseException(
                    "The MaxMind DB file's data section exceeds the maximum depth"
                );
            }

            // The value at the pointer's position was charged by its containing
            // array or map, so the target costs nothing more. Only the depth
            // grows.
            [$result] = $this->decodeWithBudget($pointer, $depth + 1, $budget, $byteBudget);

            return [$result, $offset];
        }

        if ($type === self::_EXTENDED) {
            $nextByte = \ord(Util::read($this->fileStream, $offset, 1));

            $type = $nextByte + 7;

            if ($type < 8) {
                throw new InvalidDatabaseException(
                    'Something went horribly wrong in the decoder. An extended type '
                    . 'resolved to a type number < 8 ('
                    . $type
                    . ')'
                );
            }

            ++$offset;
        }

        [$size, $offset] = $this->sizeFromCtrlByte($ctrlByte, $offset);

        return $this->decodeByType($type, $offset, $size, $depth, $budget, $byteBudget);
    }

    /**
     * @param int<0, max> $size
     *
     * @return array{0:mixed, 1:int}
     */
    private function decodeByType(int $type, int $offset, int $size, int $depth, int &$budget, int &$byteBudget): array
    {
        switch ($type) {
            case self::_MAP:
                return $this->decodeMap($size, $offset, $depth, $budget, $byteBudget);

            case self::_ARRAY:
                return $this->decodeArray($size, $offset, $depth, $budget, $byteBudget);

            case self::_BOOLEAN:
                return [$this->decodeBoolean($size), $offset];

            case self::_BYTES:
            case self::_UTF8_STRING:
                // A string or bytes value is copied into a native string, so N
                // pointers to one large value copy N times its length. Charge
                // the payload against the byte budget wherever it is decoded,
                // including inline inside a pointed-to container, so a shared
                // target recharges each time it is followed. Compare before
                // subtracting so an oversized declared size cannot drive the
                // budget negative. A total exactly at the limit is allowed.
                if ($size > $byteBudget) {
                    throw new InvalidDatabaseException(
                        "The MaxMind DB file's data section exceeds the maximum payload size"
                    );
                }
                $byteBudget -= $size;

                return [Util::read($this->fileStream, $offset, $size), $offset + $size];
        }

        // The remaining valid types are fixed-width scalars, none wider than a
        // uint128. A few other control bytes also reach here: the container
        // (12) and end-marker (13) types, and any unknown extended type. The
        // size guard below rejects one that declares an oversized size, and the
        // default case at the end of the switch rejects the rest. Reject an
        // oversized declared size before materializing the bytes, so an
        // oversized variable-length integer cannot amplify the read.
        if ($size > self::MAX_SCALAR_BYTES) {
            throw new InvalidDatabaseException(
                "The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)"
            );
        }

        $newOffset = $offset + $size;
        $bytes = Util::read($this->fileStream, $offset, $size);

        switch ($type) {
            case self::_DOUBLE:
                $this->verifySize(8, $size);

                return [$this->decodeDouble($bytes), $newOffset];

            case self::_FLOAT:
                $this->verifySize(4, $size);

                return [$this->decodeFloat($bytes), $newOffset];

            case self::_INT32:
                return [$this->decodeInt32($bytes, $size), $newOffset];

            case self::_UINT16:
            case self::_UINT32:
            case self::_UINT64:
            case self::_UINT128:
                return [$this->decodeUint($bytes, $size), $newOffset];

            default:
                throw new InvalidDatabaseException(
                    'Unknown or unexpected type: ' . $type
                );
        }
    }

    private function verifySize(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new InvalidDatabaseException(
                "The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)"
            );
        }
    }

    /**
     * Applies the per-lookup limits when entering a container. The depth limit
     * stops cycles and over-deep data (checked here and at pointer follows,
     * the only places depth grows). The value budget is charged per declared
     * element up front, so an oversized declared size is rejected before the
     * loop reads anything. A pointer element costs nothing more when it is
     * followed: its slot is charged here, and a container it resolves to
     * charges its own children each time it is decoded, which is what bounds
     * a fan-out through shared targets.
     */
    private function enterContainer(
        int $size,
        int $depth,
        int &$budget,
        int $valuesPerEntry = 1
    ): void {
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidDatabaseException(
                "The MaxMind DB file's data section exceeds the maximum depth"
            );
        }
        // Compare with a division rather than multiplying the declared size, so
        // an oversized declaration cannot overflow the integer on 32-bit builds
        // before the budget check runs.
        if ($size > intdiv($budget, $valuesPerEntry)) {
            throw new InvalidDatabaseException(
                "The MaxMind DB file's data section exceeds the maximum number of values"
            );
        }
        $budget -= $size * $valuesPerEntry;
    }

    /**
     * @return array{0:array<mixed>, 1:int}
     */
    private function decodeArray(int $size, int $offset, int $depth, int &$budget, int &$byteBudget): array
    {
        $this->enterContainer($size, $depth, $budget);

        $array = [];

        for ($i = 0; $i < $size; ++$i) {
            [$value, $offset] = $this->decodeWithBudget($offset, $depth + 1, $budget, $byteBudget);
            $array[] = $value;
        }

        return [$array, $offset];
    }

    private function decodeBoolean(int $size): bool
    {
        return $size !== 0;
    }

    private function decodeDouble(string $bytes): float
    {
        // This assumes IEEE 754 doubles, but most (all?) modern platforms
        // use them.
        $rc = unpack('E', $bytes);
        if ($rc === false) {
            throw new InvalidDatabaseException(
                'Could not unpack a double value from the given bytes.'
            );
        }
        [, $double] = $rc;

        return $double;
    }

    private function decodeFloat(string $bytes): float
    {
        // This assumes IEEE 754 floats, but most (all?) modern platforms
        // use them.
        $rc = unpack('G', $bytes);
        if ($rc === false) {
            throw new InvalidDatabaseException(
                'Could not unpack a float value from the given bytes.'
            );
        }
        [, $float] = $rc;

        return $float;
    }

    private function decodeInt32(string $bytes, int $size): int
    {
        switch ($size) {
            case 0:
                return 0;

            case 1:
            case 2:
            case 3:
                $bytes = str_pad($bytes, 4, "\x00", \STR_PAD_LEFT);

                break;

            case 4:
                break;

            default:
                throw new InvalidDatabaseException(
                    "The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)"
                );
        }

        $rc = unpack('l', $this->maybeSwitchByteOrder($bytes));
        if ($rc === false) {
            throw new InvalidDatabaseException(
                'Could not unpack a 32bit integer value from the given bytes.'
            );
        }
        [, $int] = $rc;

        return $int;
    }

    /**
     * @return array{0:array<string, mixed>, 1:int}
     */
    private function decodeMap(int $size, int $offset, int $depth, int &$budget, int &$byteBudget): array
    {
        // A map entry decodes a key and a value, so it costs two values.
        $this->enterContainer($size, $depth, $budget, 2);

        $map = [];

        for ($i = 0; $i < $size; ++$i) {
            [$key, $offset] = $this->decodeWithBudget($offset, $depth + 1, $budget, $byteBudget);
            [$value, $offset] = $this->decodeWithBudget($offset, $depth + 1, $budget, $byteBudget);
            $map[$key] = $value;
        }

        return [$map, $offset];
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function decodePointer(int $ctrlByte, int $offset): array
    {
        $pointerSize = (($ctrlByte >> 3) & 0x3) + 1;

        $buffer = Util::read($this->fileStream, $offset, $pointerSize);
        $offset += $pointerSize;

        switch ($pointerSize) {
            case 1:
                $packed = \chr($ctrlByte & 0x7) . $buffer;
                $rc = unpack('n', $packed);
                if ($rc === false) {
                    throw new InvalidDatabaseException(
                        'Could not unpack an unsigned short value from the given bytes (pointerSize is 1).'
                    );
                }
                [, $pointer] = $rc;
                $pointer += $this->pointerBase;

                break;

            case 2:
                $packed = "\x00" . \chr($ctrlByte & 0x7) . $buffer;
                $rc = unpack('N', $packed);
                if ($rc === false) {
                    throw new InvalidDatabaseException(
                        'Could not unpack an unsigned long value from the given bytes (pointerSize is 2).'
                    );
                }
                [, $pointer] = $rc;
                $pointer += $this->pointerBase + 2048;

                break;

            case 3:
                $packed = \chr($ctrlByte & 0x7) . $buffer;

                // It is safe to use 'N' here, even on 32 bit machines as the
                // first bit is 0.
                $rc = unpack('N', $packed);
                if ($rc === false) {
                    throw new InvalidDatabaseException(
                        'Could not unpack an unsigned long value from the given bytes (pointerSize is 3).'
                    );
                }
                [, $pointer] = $rc;
                $pointer += $this->pointerBase + 526336;

                break;

            case 4:
                // We cannot use unpack here as we might overflow on 32 bit
                // machines
                $pointerOffset = $this->decodeUint($buffer, $pointerSize);

                $pointerBase = $this->pointerBase;

                if (\PHP_INT_MAX - $pointerBase >= $pointerOffset) {
                    $pointer = $pointerOffset + $pointerBase;
                } else {
                    throw new \RuntimeException(
                        'The database offset is too large to be represented on your platform.'
                    );
                }

                break;

            default:
                throw new InvalidDatabaseException(
                    'Unexpected pointer size ' . $pointerSize
                );
        }

        return [$pointer, $offset];
    }

    // @phpstan-ignore-next-line
    private function decodeUint(string $bytes, int $byteLength)
    {
        if ($byteLength === 0) {
            return 0;
        }

        // PHP integers are signed. PHP_INT_SIZE - 1 is the number of
        // complete bytes that can be converted to an integer. However,
        // we can convert another byte if the leading bit is zero.
        $useRealInts = $byteLength <= \PHP_INT_SIZE - 1
            || ($byteLength === \PHP_INT_SIZE && (\ord($bytes[0]) & 0x80) === 0);

        if ($useRealInts) {
            $integer = 0;
            for ($i = 0; $i < $byteLength; ++$i) {
                $part = \ord($bytes[$i]);
                $integer = ($integer << 8) + $part;
            }

            return $integer;
        }

        // We only use gmp or bcmath if the final value is too big
        $integerAsString = '0';
        for ($i = 0; $i < $byteLength; ++$i) {
            $part = \ord($bytes[$i]);

            if (\extension_loaded('gmp')) {
                $integerAsString = gmp_strval(gmp_add(gmp_mul($integerAsString, '256'), $part));
            } elseif (\extension_loaded('bcmath')) {
                $integerAsString = bcadd(bcmul($integerAsString, '256'), (string) $part);
            } else {
                throw new \RuntimeException(
                    'The gmp or bcmath extension must be installed to read this database.'
                );
            }
        }

        return $integerAsString;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function sizeFromCtrlByte(int $ctrlByte, int $offset): array
    {
        $size = $ctrlByte & 0x1F;

        if ($size < 29) {
            return [$size, $offset];
        }

        $bytesToRead = $size - 28;
        $bytes = Util::read($this->fileStream, $offset, $bytesToRead);

        if ($size === 29) {
            $size = 29 + \ord($bytes);
        } elseif ($size === 30) {
            $rc = unpack('n', $bytes);
            if ($rc === false) {
                throw new InvalidDatabaseException(
                    'Could not unpack an unsigned short value from the given bytes.'
                );
            }
            [, $adjust] = $rc;
            $size = 285 + $adjust;
        } else {
            $rc = unpack('N', "\x00" . $bytes);
            if ($rc === false) {
                throw new InvalidDatabaseException(
                    'Could not unpack an unsigned long value from the given bytes.'
                );
            }
            [, $adjust] = $rc;
            $size = $adjust + 65821;
        }

        return [$size, $offset + $bytesToRead];
    }

    private function maybeSwitchByteOrder(string $bytes): string
    {
        return $this->switchByteOrder ? strrev($bytes) : $bytes;
    }

    private function isPlatformLittleEndian(): bool
    {
        $testint = 0x00FF;
        $packed = pack('S', $testint);
        $rc = unpack('v', $packed);
        if ($rc === false) {
            throw new InvalidDatabaseException(
                'Could not unpack an unsigned short value from the given bytes.'
            );
        }

        return $testint === current($rc);
    }
}
