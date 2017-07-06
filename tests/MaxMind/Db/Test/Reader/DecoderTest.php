<?php

namespace MaxMind\Db\Test\Reader;

use MaxMind\Db\Reader\Decoder;

/**
 * @coversNothing
 */
class DecoderTest extends \PHPUnit_Framework_TestCase
{
    private $arrays = [
        [
            'expected' => [],
            'input' => [0x0, 0x4],
            'name' => 'empty',
        ],
        [
            'expected' => ['Foo'],
            'input' => [0x1, 0x4, /* Foo */
                0x43, 0x46, 0x6f, 0x6f, ],
            'name' => 'one element',
        ],
        [
            'expected' => ['Foo', '人'],
            'input' => [
                0x2, 0x4,
                /* Foo */
                0x43, 0x46, 0x6f, 0x6f,
                /* 人 */
                0x43, 0xe4, 0xba, 0xba,
            ],
            'name' => 'two elements',
        ],
    ];

    private $booleans = [
        [
            'expected' => false,
            'input' => [0x0, 0x7],
        ],
        [
            'expected' => true,
            'input' => [0x1, 0x7],
        ],
    ];

    private $doubles = [
        ['expected' => 0.0, 'input' => [0x68, 0x0, 0x0, 0x0, 0x0, 0x0, 0x0, 0x0, 0x0]],
        ['expected' => 0.5, 'input' => [0x68, 0x3F, 0xE0, 0x0, 0x0, 0x0, 0x0, 0x0, 0x0]],
        ['expected' => 3.14159265359, 'input' => [0x68, 0x40, 0x9, 0x21, 0xFB, 0x54, 0x44, 0x2E, 0xEA]],
        ['expected' => 123.0, 'input' => [0x68, 0x40, 0x5E, 0xC0, 0x0, 0x0, 0x0, 0x0, 0x0]],
        ['expected' => 1073741824.12457, 'input' => [0x68, 0x41, 0xD0, 0x0, 0x0, 0x0, 0x7, 0xF8, 0xF4]],
        ['expected' => -0.5, 'input' => [0x68, 0xBF, 0xE0, 0x0, 0x0, 0x0, 0x0, 0x0, 0x0]],
        ['expected' => -3.14159265359, 'input' => [0x68, 0xC0, 0x9, 0x21, 0xFB, 0x54, 0x44, 0x2E, 0xEA]],
        ['expected' => -1073741824.12457, 'input' => [0x68, 0xC1, 0xD0, 0x0, 0x0, 0x0, 0x7, 0xF8, 0xF4]],
    ];

    private $floats = [
        ['expected' => 0.0, 'input' => [0x4, 0x8, 0x0, 0x0, 0x0, 0x0]],
        ['expected' => 1.0, 'input' => [0x4, 0x8, 0x3F, 0x80, 0x0, 0x0]],
        ['expected' => 1.1, 'input' => [0x4, 0x8, 0x3F, 0x8C, 0xCC, 0xCD]],
        ['expected' => 3.14, 'input' => [0x4, 0x8, 0x40, 0x48, 0xF5, 0xC3]],
        ['expected' => 9999.99, 'input' => [0x4, 0x8, 0x46, 0x1C, 0x3F, 0xF6]],
        ['expected' => -1.0, 'input' => [0x4, 0x8, 0xBF, 0x80, 0x0, 0x0]],
        ['expected' => -1.1, 'input' => [0x4, 0x8, 0xBF, 0x8C, 0xCC, 0xCD]],
        ['expected' => -3.14, 'input' => [0x4, 0x8, 0xC0, 0x48, 0xF5, 0xC3]],
        ['expected' => -9999.99, 'input' => [0x4, 0x8, 0xC6, 0x1C, 0x3F, 0xF6]],
    ];

    // PHP can't have arrays/objects as keys. Maybe redo all of the tests
    // this way so that we can use one test runner
    private $maps = [
        [
            'expected' => [],
            'input' => [0xe0],
            'name' => 'empty',
        ],
        [
            'expected' => ['en' => 'Foo'],
            'input' => [0xe1, /* en */
                0x42, 0x65, 0x6e,
                /* Foo */
                0x43, 0x46, 0x6f, 0x6f, ],
            'name' => 'one key',
        ],
        [
            'expected' => ['en' => 'Foo', 'zh' => '人'],
            'input' => [
                0xe2,
                /* en */
                0x42, 0x65, 0x6e,
                /* Foo */
                0x43, 0x46, 0x6f, 0x6f,
                /* zh */
                0x42, 0x7a, 0x68,
                /* 人 */
                0x43, 0xe4, 0xba, 0xba,
            ],
            'name' => 'two keys',
        ],
        [
            'expected' => ['name' => ['en' => 'Foo', 'zh' => '人']],
            'input' => [
                0xe1,
                /* name */
                0x44, 0x6e, 0x61, 0x6d, 0x65, 0xe2,
                /* en */
                0x42, 0x65, 0x6e,
                /* Foo */
                0x43, 0x46, 0x6f, 0x6f,
                /* zh */
                0x42, 0x7a, 0x68,
                /* 人 */
                0x43, 0xe4, 0xba, 0xba,
            ],
            'name' => 'nested',
        ],
        [
            'expected' => ['languages' => ['en', 'zh']],
            'input' => [
                0xe1,
                /* languages */
                0x49, 0x6c, 0x61, 0x6e, 0x67, 0x75, 0x61,
                0x67, 0x65, 0x73,
                /* array */
                0x2, 0x4,
                /* en */
                0x42, 0x65, 0x6e,
                /* zh */
                0x42, 0x7a, 0x68,
            ],
            'name' => 'map with array in it',
        ],
    ];

    private $pointers = [
        ['expected' => 0, 'input' => [0x20, 0x0]],
        ['expected' => 5, 'input' => [0x20, 0x5]],
        ['expected' => 10, 'input' => [0x20, 0xa]],
        ['expected' => 1023, 'input' => [0x23, 0xff]],
        ['expected' => 3017, 'input' => [0x28, 0x3, 0xc9]],
        ['expected' => 524283, 'input' => [0x2f, 0xf7, 0xfb]],
        ['expected' => 526335, 'input' => [0x2f, 0xff, 0xff]],
        ['expected' => 134217726, 'input' => [0x37, 0xf7, 0xf7, 0xfe]],
        ['expected' => 134744063, 'input' => [0x37, 0xff, 0xff, 0xff]],
        ['expected' => 2147483647, 'input' => [0x38, 0x7f, 0xff, 0xff, 0xff]],
        ['expected' => 4294967295, 'input' => [0x38, 0xff, 0xff, 0xff, 0xff]],
    ];

    private $uint16 = [
        ['expected' => 0, 'input' => [0xa0]],
        ['expected' => 255, 'input' => [0xa1, 0xff]],
        ['expected' => 500, 'input' => [0xa2, 0x1, 0xf4]],
        ['expected' => 10872, 'input' => [0xa2, 0x2a, 0x78]],
        ['expected' => 65535, 'input' => [0xa2, 0xff, 0xff]],
    ];

    private $int32 = [
        ['expected' => 0, 'input' => [0x0, 0x1]],
        ['expected' => -1, 'input' => [0x4, 0x1, 0xff, 0xff, 0xff, 0xff]],
        ['expected' => 255, 'input' => [0x1, 0x1, 0xff]],
        ['expected' => -255, 'input' => [0x4, 0x1, 0xff, 0xff, 0xff, 0x1]],
        ['expected' => 500, 'input' => [0x2, 0x1, 0x1, 0xf4]],
        ['expected' => -500, 'input' => [0x4, 0x1, 0xff, 0xff, 0xfe, 0xc]],
        ['expected' => 65535, 'input' => [0x2, 0x1, 0xff, 0xff]],
        ['expected' => -65535, 'input' => [0x4, 0x1, 0xff, 0xff, 0x0, 0x1]],
        ['expected' => 16777215, 'input' => [0x3, 0x1, 0xff, 0xff, 0xff]],
        ['expected' => -16777215, 'input' => [0x4, 0x1, 0xff, 0x0, 0x0, 0x1]],
        ['expected' => 2147483647, 'input' => [0x4, 0x1, 0x7f, 0xff, 0xff, 0xff]],
        ['expected' => -2147483647, 'input' => [0x4, 0x1, 0x80, 0x0, 0x0, 0x1]],
    ];

    private function strings()
    {
        $strings = [
            ['expected' => '', 'input' => [0x40]],
            ['expected' => '1', 'input' => [0x41, 0x31]],
            ['expected' => '人', 'input' => [0x43, 0xE4, 0xBA, 0xBA]],
            ['expected' => '123', 'input' => [0x43, 0x31, 0x32, 0x33]],
            ['expected' => '123456789012345678901234567', 'input' => [0x5b, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35, 0x36,
                0x37, ]],
            ['expected' => '1234567890123456789012345678', 'input' => [0x5c, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35, 0x36,
                0x37, 0x38, ]],
            ['expected' => '12345678901234567890123456789', 'input' => [0x5d, 0x0, 0x31, 0x32, 0x33,
                0x34, 0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, ]],
            ['expected' => '123456789012345678901234567890', 'input' => [0x5d, 0x1, 0x31, 0x32, 0x33,
                0x34, 0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, 0x30, ]],

            ['expected' => str_repeat('x', 500),
             'input' => array_pad(
                    [0x5e, 0x0, 0xd7],
                    503,
                    0x78
                ),
            ],
            [
             'expected' => str_repeat('x', 2000),
              'input' => array_pad(
                    [0x5e, 0x6, 0xb3],
                    2003,
                    0x78
                ),
            ],
            [
                'expected' => str_repeat('x', 70000),
                'input' => array_pad(
                    [0x5f, 0x0, 0x10, 0x53],
                    70004,
                    0x78
                ),
            ],
        ];

        return $strings;
    }

    private $uint32 = [
        ['expected' => 0, 'input' => [0xc0]],
        ['expected' => 255, 'input' => [0xc1, 0xff]],
        ['expected' => 500, 'input' => [0xc2, 0x1, 0xf4]],
        ['expected' => 10872, 'input' => [0xc2, 0x2a, 0x78]],
        ['expected' => 65535, 'input' => [0xc2, 0xff, 0xff]],
        ['expected' => 16777215, 'input' => [0xc3, 0xff, 0xff, 0xff]],
        ['expected' => 4294967295, 'input' => [0xc4, 0xff, 0xff, 0xff, 0xff]],
    ];

    private function bytes()
    {
        // ugly deep clone
        $bytes = unserialize(serialize($this->strings()));

        foreach ($bytes as $key => $test) {
            $test['input'][0] ^= 0xc0;
        }

        return $bytes;
    }

    public function generateLargeUint($bits)
    {
        $ctrlByte = $bits === 64 ? 0x2 : 0x3;

        $uints = [
            0 => [0x0, $ctrlByte],
            500 => [0x2, $ctrlByte, 0x1, 0xf4],
            10872 => [0x2, $ctrlByte, 0x2a, 0x78],
        ];

        for ($power = 1; $power <= $bits / 8; $power++) {
            $expected = bcsub(bcpow(2, 8 * $power), 1);
            $input = [$power, $ctrlByte];
            for ($i = 2; $i < 2 + $power; $i++) {
                $input[$i] = 0xff;
            }
            $uints[$expected] = $input;
        }

        return $uints;
    }

    public function testArrays()
    {
        $this->validateTypeDecodingList('array', $this->arrays);
    }

    public function testBooleans()
    {
        $this->validateTypeDecodingList('boolean', $this->booleans);
    }

    public function testBytes()
    {
        $this->validateTypeDecodingList('byte', $this->bytes());
    }

    public function testDoubles()
    {
        $this->validateTypeDecodingList('double', $this->doubles);
    }

    public function testFloats()
    {
        $this->validateTypeDecodingList('float', $this->floats);
    }

    public function testInt32()
    {
        $this->validateTypeDecodingList('int32', $this->int32);
    }

    public function testMaps()
    {
        $this->validateTypeDecodingList('map', $this->maps);
    }

    public function testPointers()
    {
        $this->validateTypeDecodingList('pointers', $this->pointers);
    }

    public function testStrings()
    {
        $this->validateTypeDecodingList('utf8_string', $this->strings());
    }

    public function testUint16()
    {
        $this->validateTypeDecodingList('uint16', $this->uint16);
    }

    public function testUint32()
    {
        $this->validateTypeDecodingList('uint32', $this->uint32);
    }

    public function testUint64()
    {
        $this->validateTypeDecoding('uint64', $this->generateLargeUint(64));
    }

    public function testUint128()
    {
        $this->validateTypeDecoding('uint128', $this->generateLargeUint(128));
    }

    private function validateTypeDecoding($type, $tests)
    {
        foreach ($tests as $expected => $input) {
            $this->checkDecoding($type, $input, $expected);
        }
    }

    private function validateTypeDecodingList($type, $tests)
    {
        foreach ($tests as $test) {
            $this->checkDecoding(
                $type,
                $test['input'],
                $test['expected'],
                isset($test['name']) ? $test['name'] : $test['input']
            );
        }
    }

    private function checkDecoding($type, $input, $expected, $name = null)
    {
        $name = $name || $expected;
        $description = "decoded $type - $name";
        $handle = fopen('php://memory', 'rw');

        foreach ($input as $byte) {
            fwrite($handle, pack('C', $byte));
        }
        fseek($handle, 0);
        $decoder = new Decoder($handle, 0, true);
        list($actual) = $decoder->decode(0);

        if ($type === 'float') {
            $actual = round($actual, 2);
        }

        $this->assertSame($expected, $actual, $description);
    }
}
