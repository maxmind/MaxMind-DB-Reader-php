<?php

namespace MaxMind\Db\Reader;

use MaxMind\Db\Reader\InvalidDatabaseException;

class Decoder
{

    private $debug = true;
    private $fileStream;
    private $pointerBase;

    private $types = array(
        0  => 'extended',
        1  => 'pointer',
        2  => 'utf8_string',
        3  => 'double',
        4  => 'bytes',
        5  => 'uint16',
        6  => 'uint32',
        7  => 'map',
        8  => 'int32',
        9  => 'uint64',
        10 => 'uint128',
        11 => 'array',
        12 => 'container',
        13 => 'end_marker',
        14 => 'boolean',
        15 => 'float',
    );

    public function __construct(
        $fileStream,
        $pointerBase = 0
    ) {
        $this->fileStream = $fileStream;
        $this->pointerBase = $pointerBase;
    }


    public function decode($offset)
    {
        list(, $ctrlByte) = unpack('C', $this->read($offset, 1));
        $offset++;

        $type = $this->types[$ctrlByte >> 5];

        if ($this->debug) {
            $this->log('Control Byte', $ctrlByte);
            $this->log('Type', $type);
        }
        // Pointers are a special case, we don't read the next $size bytes, we
        // use the size to determine the length of the pointer and then follow
        // it.
        if ($type == 'pointer') {
            list($pointer, $offset) = $this->decodePointer($ctrlByte, $offset);

            // for unit testing
            if ($this->POINTER_TEST_HACK) {
                return $pointer;
            }
            $result = $this->decode($pointer);

            return array($result, $offset);
        }

        if ($type == 'extended') {
            list(, $nextByte) = unpack('C', $this->read($offset, 1));

            $typeNum = $nextByte + 7;

            if ($this->debug) {
                $this->log('Offset', $offset);
                $this->log('Next Byte', $nextByte);
                $this->log('Type', $this->types[$typeNum]);
            }

            if ($typeNum < 8) {
                throw new InvalidDatabaseException(
                    "Something went horribly wrong in the decoder. An extended type "
                    . "resolved to a type number < 8 ("
                    . $this->types[$typeNum]
                    . ")"
                );
            }

            $type = $this->types[$typeNum];
            $offset++;
        }

        list($size, $offset) = $this->sizeFromCtrlByte($ctrlByte, $offset);

        return $this->decodeByType($type, $offset, $size);
    }

    private function decodeByType($type, $offset, $size)
    {
        // MAP, ARRAY, and BOOLEAN do not use $newOffset as we don't read the
        // next <code>size</code> bytes. For all other types, we do.
        $newOffset = $offset + $size;
        $bytes = $this->read($offset, $size);
        if ($this->debug) {
            $this->logBytes('Bytes to Decode', $bytes);
        }
        switch ($type) {
            case 'map':
                return $this->decodeMap($bytes, $offset);
            case 'array':
                return $this->decodeArray($bytes, $offset);
            case 'boolean':
                return array($this->decodeBoolean($bytes), $offset);
            case 'utf8_string':
                return array($this->decodeString($bytes), $newOffset);
            case 'double':
                return array($this->decodeDouble($bytes), $newOffset);
            case 'float':
                return array($this->decodeFloat($bytes), $newOffset);
            case 'bytes':
                return array($this->getByteArray($bytes), $newOffset);
            case 'uint16':
                return array($this->decodeUint16($bytes), $newOffset);
            case 'uint32':
                return array($this->decodeUint32($bytes), $newOffset);
            case 'int32':
                return array($this->decodeInt32($bytes), $newOffset);
            case 'uint64':
                return aray($this->decodeUint64($bytes), $newOffset);
            case 'uint128':
                return array($this->decodeUint128($bytes), $newOffset);
            default:
                throw new InvalidDatabaseException(
                    "Unknown or unexpected type: " + $type
                );
        }
    }

    private function decodeBoolean($size)
    {
        return $size == 0 ? false : true;
    }

    private function decodeDouble($bits)
    {
        // FIXME - this will only work on little endian machines with
        // IEEE 754 doubles
        list(, $double) = unpack('d', strrev($bits));
        return $double;
    }

    private function decodeFloat($bits)
    {
        // FIXME - this will only work on little endian machines with
        // IEEE 754 floats
        list(, $float) = unpack('f', strrev($bits));
        return $float;
    }

    private function decodeInt32($bytes)
    {
        // PHP doesn't have a big-endian signed-long unpack
        $unpacked = $this->decodeUint32($bytes);
        $firstBit = $unpacked >> 31;
        $signum = $firstBit ? -1 : 1;

        return $signum * ($unpacked & 0x7FFFFFFF);
    }

    private $pointerValueOffset = array(
        1 => 0,
        2 => 2048,
        3 => 526336,
        4 => 0,
        );

    private function decodePointer($ctrlByte, $offset)
    {
        $pointerSize = (($ctrlByte >> 3) & 0x3) + 1;

        // FIXME - need to update $offset
        $buffer = $this->read($offset, $pointerSize);

        $packed = $pointerSize == 4
            ? $buffer
            : ( pack(C, $ctrlByte & 0x3) ) + $buffer;

        $packed = $this->zeroPadLeft($packed, 4);

        list(, $unpacked) = unpack('N', $packed);
        $pointer = $unpacked + $this->pointerBase + $this->pointerValueOffset[$pointerSize];

        return array($pointer, $offset);
    }


    private function decodeUint16($bytes)
    {
        // No big-endian unsigned short format
        return $this->decodeUint32($bytes);
    }

    private function decodeUint32($bytes)
    {
        list(, $int) = unpack('N', $this->zeroPadLeft($bytes, 4));
        return $int;
    }

    private function decodeUint64($bytes)
    {
        return $this->decodeBigUint($bytes, 8);
    }

    private function decodeUint128($bytes)
    {
        return $this->decodeBigUint($bytes, 16);
    }

    private function decodeBigUint($bytes, $size)
    {
        $size /= 4;
        $integer = 0;
        $unpacked = array_merge(unpack("N$size", $bytes));
        foreach ($unpacked as $part) {
            // No bitwise operators with bcmath :'-(
            $integer = bcadd(bcmul($integer, bcpow($integer, 2)), $part);
        }
        return $integer;
    }

    private function decodeString($bytes)
    {
        // XXX - NOOP. As far as I know, the end user has to explicitly set the
        // encoding in PHP. Strings are just bytes.
        return $bytes;
    }

    private function read($offset, $numberOfBytes)
    {
        fseek($this->fileStream, $offset);
        return fread($this->fileStream, $numberOfBytes);
    }

    private function sizeFromCtrlByte($ctrlByte, $offset)
    {
        $size = $ctrlByte & 0x1f;
        $bytesToRead = $size < 29 ? 0 : $size - 28;
        $bytes = $this->read($offset, $size);
        $decoded = $this->decodeUint32($bytes);

        if ($size == 29) {
            $size = 29 + $decoded;
        } elseif ($size == 30) {
            $size = 285 + $decoded;
        } elseif ($size > 30) {
            $size = $decoded & (0x0FFFFFFF >> (32 - (8 * $bytesToRead)))
                + 65821;
        }
        return array($size, $offset + $bytesToRead);
    }

    private function zeroPadLeft($content, $desiredLength)
    {
        $padLength = $desiredLength - strlen($content);

        return str_pad($content, $padLength, "\x00", STR_PAD_LEFT);
    }

    private function log($name, $message)
    {
        print("$name: $message\n");
    }

    private function logBytes($name, $bytes)
    {
        $message = implode(',', array_map('dechex', unpack('C*', $bytes)));
        $this->log($name, $message);
    }
}
