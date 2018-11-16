<?php

namespace MaxMind\Db\Reader;

\define(__NAMESPACE__ . '\_MM_MAX_INT_BYTES', log(PHP_INT_MAX, 2) / 8);

class Decoder
{
    private $fileStream;
    private $pointerBase;
    // This is only used for unit testing
    private $pointerTestHack;
    private $switchByteOrder;

    const _EXTENDED = 0;
    const _POINTER = 1;
    const _UTF8_STRING = 2;
    const _DOUBLE = 3;
    const _BYTES = 4;
    const _UINT16 = 5;
    const _UINT32 = 6;
    const _MAP = 7;
    const _INT32 = 8;
    const _UINT64 = 9;
    const _UINT128 = 10;
    const _ARRAY = 11;
    const _CONTAINER = 12;
    const _END_MARKER = 13;
    const _BOOLEAN = 14;
    const _FLOAT = 15;

    public function __construct(
        $fileStream,
        $pointerBase = 0,
        $pointerTestHack = false
    ) {
        $this->fileStream = $fileStream;
        $this->pointerBase = $pointerBase;
        $this->pointerTestHack = $pointerTestHack;

        $this->switchByteOrder = $this->isPlatformLittleEndian();
    }

    public function decode($offset)
    {
        list(, $ctrlByte) = unpack(
            'C',
            Util::read($this->fileStream, $offset, 1)
        );
        ++$offset;

        $type = $ctrlByte >> 5;

        // Pointers are a special case, we don't read the next $size bytes, we
        // use the size to determine the length of the pointer and then follow
        // it.
        if ($type === self::_POINTER) {
            list($pointer, $offset) = $this->decodePointer($ctrlByte, $offset);

            // for unit testing
            if ($this->pointerTestHack) {
                return [$pointer];
            }

            list($result) = $this->decode($pointer);

            return [$result, $offset];
        }

        if ($type === self::_EXTENDED) {
            list(, $nextByte) = unpack(
                'C',
                Util::read($this->fileStream, $offset, 1)
            );

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

        list($size, $offset) = $this->sizeFromCtrlByte($ctrlByte, $offset);

        return $this->decodeByType($type, $offset, $size);
    }

    private function decodeByType($type, $offset, $size)
    {
        switch ($type) {
            case self::_MAP:
                return $this->decodeMap($size, $offset);
            case self::_ARRAY:
                return $this->decodeArray($size, $offset);
            case self::_BOOLEAN:
                return [$this->decodeBoolean($size), $offset];
        }

        $newOffset = $offset + $size;
        $bytes = Util::read($this->fileStream, $offset, $size);
        switch ($type) {
            case self::_BYTES:
            case self::_UTF8_STRING:
                return [$bytes, $newOffset];
            case self::_DOUBLE:
                $this->verifySize(8, $size);

                return [$this->decodeDouble($bytes), $newOffset];
            case self::_FLOAT:
                $this->verifySize(4, $size);

                return [$this->decodeFloat($bytes), $newOffset];
            case self::_UINT16:
            case self::_UINT32:
                return [$this->decodeUint($bytes, $size, 0), $newOffset];
            case self::_INT32:
                return [$this->decodeInt32($bytes, $size), $newOffset];
            case self::_UINT64:
            case self::_UINT128:
                return [$this->decodeUint($bytes, $size, 0), $newOffset];
            default:
                throw new InvalidDatabaseException(
                    'Unknown or unexpected type: ' . $type
                );
        }
    }

    private function verifySize($expected, $actual)
    {
        if ($expected !== $actual) {
            throw new InvalidDatabaseException(
                "The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)"
            );
        }
    }

    private function decodeArray($size, $offset)
    {
        $array = [];

        for ($i = 0; $i < $size; ++$i) {
            list($value, $offset) = $this->decode($offset);
            array_push($array, $value);
        }

        return [$array, $offset];
    }

    private function decodeBoolean($size)
    {
        return $size === 0 ? false : true;
    }

    private function decodeDouble($bits)
    {
        // This assumes IEEE 754 doubles, but most (all?) modern platforms
        // use them.
        //
        // We are not using the "E" format as that was only added in
        // 7.0.15 and 7.1.1. As such, we must switch byte order on
        // little endian machines.
        list(, $double) = unpack('d', $this->maybeSwitchByteOrder($bits));

        return $double;
    }

    private function decodeFloat($bits)
    {
        // This assumes IEEE 754 floats, but most (all?) modern platforms
        // use them.
        //
        // We are not using the "G" format as that was only added in
        // 7.0.15 and 7.1.1. As such, we must switch byte order on
        // little endian machines.
        list(, $float) = unpack('f', $this->maybeSwitchByteOrder($bits));

        return $float;
    }

    private function decodeInt32($bytes, $size)
    {
        switch ($size) {
            case 0:
                return 0;
            case 1:
            case 2:
            case 3:
                $bytes = str_pad($bytes, 4, "\x00", STR_PAD_LEFT);
                break;
            case 4:
                break;
            default:
                throw new InvalidDatabaseException(
                    "The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)"
                );
        }

        list(, $int) = unpack('l', $this->maybeSwitchByteOrder($bytes));

        return $int;
    }

    private function decodeMap($size, $offset)
    {
        $map = [];

        for ($i = 0; $i < $size; ++$i) {
            list($key, $offset) = $this->decode($offset);
            list($value, $offset) = $this->decode($offset);
            $map[$key] = $value;
        }

        return [$map, $offset];
    }

    private $pointerValueOffset = [
        1 => 0,
        2 => 2048,
        3 => 526336,
        4 => 0,
    ];

    private function decodePointer($ctrlByte, $offset)
    {
        $pointerSize = (($ctrlByte >> 3) & 0x3) + 1;

        $buffer = Util::read($this->fileStream, $offset, $pointerSize);
        $offset = $offset + $pointerSize;

        $packed = $pointerSize === 4
            ? $buffer
            : (pack('C', $ctrlByte & 0x7)) . $buffer;

        $base = $this->pointerBase + $this->pointerValueOffset[$pointerSize];
        $pointer = $this->decodeUint($packed, $pointerSize, $base);

        return [$pointer, $offset];
    }

    private function decodeUint($bytes, $byteLength, $base)
    {
        if ($byteLength === 0) {
            return $base;
        }

        $unpacked = unpack('C*', $bytes);

        $integer = 0;

        foreach ($unpacked as $part) {
            // We only use gmp or bcmath if the final value is too big
            if ($byteLength <= _MM_MAX_INT_BYTES) {
                $integer = ($integer << 8) + $part;
            } elseif (\extension_loaded('gmp')) {
                $integer = gmp_add(gmp_mul($integer, 256), $part);
            } elseif (\extension_loaded('bcmath')) {
                $integer = bcadd(bcmul($integer, 256), $part);
            } else {
                throw new \RuntimeException(
                    'The gmp or bcmath extension must be installed to read this database.'
                );
            }
        }

        if ($base > 0) {
            $byteLength = $byteLength + log($base, 2) / 8;
        }

        if ($byteLength <= _MM_MAX_INT_BYTES) {
            return $integer + $base;
        } elseif (\extension_loaded('gmp')) {
            return gmp_strval(gmp_add($integer, $base));
        } elseif (\extension_loaded('bcmath')) {
            return bcadd($integer, $base);
        }
        throw new \RuntimeException(
            'The gmp or bcmath extension must be installed to read this database.'
        );
    }

    private function sizeFromCtrlByte($ctrlByte, $offset)
    {
        $size = $ctrlByte & 0x1f;
        $bytesToRead = $size < 29 ? 0 : $size - 28;
        $bytes = Util::read($this->fileStream, $offset, $bytesToRead);
        $decoded = $this->decodeUint($bytes, $bytesToRead, 0);

        if ($size === 29) {
            $size = 29 + $decoded;
        } elseif ($size === 30) {
            $size = 285 + $decoded;
        } elseif ($size > 30) {
            $size = ($decoded & (0x0FFFFFFF >> (32 - (8 * $bytesToRead))))
                + 65821;
        }

        return [$size, $offset + $bytesToRead];
    }

    private function maybeSwitchByteOrder($bytes)
    {
        return $this->switchByteOrder ? strrev($bytes) : $bytes;
    }

    private function isPlatformLittleEndian()
    {
        $testint = 0x00FF;
        $packed = pack('S', $testint);

        return $testint === current(unpack('v', $packed));
    }
}
