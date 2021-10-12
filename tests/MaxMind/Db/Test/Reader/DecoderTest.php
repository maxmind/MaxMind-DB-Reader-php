<?php

declare(strict_types=1);

namespace MaxMind\Db\Test\Reader;

use MaxMind\Db\Reader\Decoder;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 *
 * @internal
 */
class DecoderTest extends TestCase
{
    /**
     * @var array<array<string, mixed>>
     */
    private $arrays = [
        [
            'expected' => [],
            'input' => [0x0, 0x4],
            'name' => 'empty',
        ],
        [
            'expected' => ['Foo'],
            'input' => [0x1, 0x4, // Foo
                0x43, 0x46, 0x6F, 0x6F, ],
            'name' => 'one element',
        ],
        [
            'expected' => ['Foo', '人'],
            'input' => [
                0x2, 0x4,
                // Foo
                0x43, 0x46, 0x6F, 0x6F,
                // 人
                0x43, 0xE4, 0xBA, 0xBA,
            ],
            'name' => 'two elements',
        ],
    ];

    /**
     * @var array<array<string, mixed>>
     */
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

    /**
     * @var array<array<string, mixed>>
     */
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

    /**
     * @var array<array<string, mixed>>
     */
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
    /**
     * @var array<array<string, mixed>>
     */
    private $maps = [
        [
            'expected' => [],
            'input' => [0xE0],
            'name' => 'empty',
        ],
        [
            'expected' => ['en' => 'Foo'],
            'input' => [0xE1, // en
                0x42, 0x65, 0x6E,
                // Foo
                0x43, 0x46, 0x6F, 0x6F, ],
            'name' => 'one key',
        ],
        [
            'expected' => ['en' => 'Foo', 'zh' => '人'],
            'input' => [
                0xE2,
                // en
                0x42, 0x65, 0x6E,
                // Foo
                0x43, 0x46, 0x6F, 0x6F,
                // zh
                0x42, 0x7A, 0x68,
                // 人
                0x43, 0xE4, 0xBA, 0xBA,
            ],
            'name' => 'two keys',
        ],
        [
            'expected' => ['name' => ['en' => 'Foo', 'zh' => '人']],
            'input' => [
                0xE1,
                // name
                0x44, 0x6E, 0x61, 0x6D, 0x65, 0xE2,
                // en
                0x42, 0x65, 0x6E,
                // Foo
                0x43, 0x46, 0x6F, 0x6F,
                // zh
                0x42, 0x7A, 0x68,
                // 人
                0x43, 0xE4, 0xBA, 0xBA,
            ],
            'name' => 'nested',
        ],
        [
            'expected' => ['languages' => ['en', 'zh']],
            'input' => [
                0xE1,
                // languages
                0x49, 0x6C, 0x61, 0x6E, 0x67, 0x75, 0x61,
                0x67, 0x65, 0x73,
                // array
                0x2, 0x4,
                // en
                0x42, 0x65, 0x6E,
                // zh
                0x42, 0x7A, 0x68,
            ],
            'name' => 'map with array in it',
        ],
    ];

    /**
     * @return array<array<string, mixed>>
     */
    private function pointers(): array
    {
        $v = [
            ['expected' => 0, 'input' => [0x20, 0x0]],
            ['expected' => 5, 'input' => [0x20, 0x5]],
            ['expected' => 10, 'input' => [0x20, 0xA]],
            ['expected' => 1023, 'input' => [0x23, 0xFF]],
            ['expected' => 3017, 'input' => [0x28, 0x3, 0xC9]],
            ['expected' => 524283, 'input' => [0x2F, 0xF7, 0xFB]],
            ['expected' => 526335, 'input' => [0x2F, 0xFF, 0xFF]],
            ['expected' => 134217726, 'input' => [0x37, 0xF7, 0xF7, 0xFE]],
            ['expected' => 2147483647, 'input' => [0x38, 0x7F, 0xFF, 0xFF, 0xFF]],
        ];

        if (\PHP_INT_MAX > 4294967295) {
            array_push($v, ['expected' => 4294967295, 'input' => [0x38, 0xFF, 0xFF, 0xFF, 0xFF]]);
        }

        return $v;
    }

    /**
     * @var array<array<string, mixed>>
     */
    private $uint16 = [
        ['expected' => 0, 'input' => [0xA0]],
        ['expected' => 255, 'input' => [0xA1, 0xFF]],
        ['expected' => 500, 'input' => [0xA2, 0x1, 0xF4]],
        ['expected' => 10872, 'input' => [0xA2, 0x2A, 0x78]],
        ['expected' => 65535, 'input' => [0xA2, 0xFF, 0xFF]],
    ];

    /**
     * @var array<array<string, mixed>>
     */
    private $int32 = [
        ['expected' => 0, 'input' => [0x0, 0x1]],
        ['expected' => -1, 'input' => [0x4, 0x1, 0xFF, 0xFF, 0xFF, 0xFF]],
        ['expected' => 255, 'input' => [0x1, 0x1, 0xFF]],
        ['expected' => -255, 'input' => [0x4, 0x1, 0xFF, 0xFF, 0xFF, 0x1]],
        ['expected' => 500, 'input' => [0x2, 0x1, 0x1, 0xF4]],
        ['expected' => -500, 'input' => [0x4, 0x1, 0xFF, 0xFF, 0xFE, 0xC]],
        ['expected' => 65535, 'input' => [0x2, 0x1, 0xFF, 0xFF]],
        ['expected' => -65535, 'input' => [0x4, 0x1, 0xFF, 0xFF, 0x0, 0x1]],
        ['expected' => 16777215, 'input' => [0x3, 0x1, 0xFF, 0xFF, 0xFF]],
        ['expected' => -16777215, 'input' => [0x4, 0x1, 0xFF, 0x0, 0x0, 0x1]],
        ['expected' => 2147483647, 'input' => [0x4, 0x1, 0x7F, 0xFF, 0xFF, 0xFF]],
        ['expected' => -2147483647, 'input' => [0x4, 0x1, 0x80, 0x0, 0x0, 0x1]],
    ];

    /**
     * @return array<array<string, mixed>>
     */
    private function strings(): array
    {
        return [
            ['expected' => '', 'input' => [0x40]],
            ['expected' => '1', 'input' => [0x41, 0x31]],
            ['expected' => '人', 'input' => [0x43, 0xE4, 0xBA, 0xBA]],
            ['expected' => '123', 'input' => [0x43, 0x31, 0x32, 0x33]],
            ['expected' => '123456789012345678901234567', 'input' => [0x5B, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35, 0x36,
                0x37, ]],
            ['expected' => '1234567890123456789012345678', 'input' => [0x5C, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35, 0x36,
                0x37, 0x38, ]],
            ['expected' => '12345678901234567890123456789', 'input' => [0x5D, 0x0, 0x31, 0x32, 0x33,
                0x34, 0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, ]],
            ['expected' => '123456789012345678901234567890', 'input' => [0x5D, 0x1, 0x31, 0x32, 0x33,
                0x34, 0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34,
                0x35, 0x36, 0x37, 0x38, 0x39, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, 0x30, ]],

            ['expected' => str_repeat('x', 500),
                'input' => array_pad(
                    [0x5E, 0x0, 0xD7],
                    503,
                    0x78
                ),
            ],
            [
                'expected' => str_repeat('x', 2000),
                'input' => array_pad(
                    [0x5E, 0x6, 0xB3],
                    2003,
                    0x78
                ),
            ],
            [
                'expected' => str_repeat('x', 70000),
                'input' => array_pad(
                    [0x5F, 0x0, 0x10, 0x53],
                    70004,
                    0x78
                ),
            ],
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function uint32(): array
    {
        return [
            ['expected' => 0, 'input' => [0xC0]],
            ['expected' => 255, 'input' => [0xC1, 0xFF]],
            ['expected' => 500, 'input' => [0xC2, 0x1, 0xF4]],
            ['expected' => 10872, 'input' => [0xC2, 0x2A, 0x78]],
            ['expected' => 65535, 'input' => [0xC2, 0xFF, 0xFF]],
            ['expected' => 16777215, 'input' => [0xC3, 0xFF, 0xFF, 0xFF]],
            ['expected' => \PHP_INT_MAX < 4294967295 ? '4294967295' : 4294967295, 'input' => [0xC4, 0xFF, 0xFF, 0xFF, 0xFF]],
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function bytes(): array
    {
        // ugly deep clone
        $bytes = unserialize(serialize($this->strings()));

        foreach ($bytes as $key => $test) {
            $test['input'][0] ^= 0xC0;
        }

        return $bytes;
    }

    public function generateLargeUint(int $bits): array
    {
        $ctrlByte = $bits === 64 ? 0x2 : 0x3;

        $uints = [
            0 => [0x0, $ctrlByte],
            500 => [0x2, $ctrlByte, 0x1, 0xF4],
            10872 => [0x2, $ctrlByte, 0x2A, 0x78],
        ];

        for ($power = 1; $power <= $bits / 8; ++$power) {
            if (\extension_loaded('gmp')) {
                $expected = gmp_strval(gmp_sub(gmp_pow('2', 8 * $power), '1'));
            } elseif (\extension_loaded('bcmath')) {
                $expected = bcsub(bcpow('2', (string) (8 * $power)), '1');
            } else {
                $this->markTestSkipped('This test requires gmp or bcmath.');
            }
            $input = [$power, $ctrlByte];
            for ($i = 2; $i < 2 + $power; ++$i) {
                $input[$i] = 0xFF;
            }
            $uints[$expected] = $input;
        }

        return $uints;
    }

    public function testArrays(): void
    {
        $this->validateTypeDecodingList('array', $this->arrays);
    }

    public function testBooleans(): void
    {
        $this->validateTypeDecodingList('boolean', $this->booleans);
    }

    public function testBytes(): void
    {
        $this->validateTypeDecodingList('byte', $this->bytes());
    }

    public function testDoubles(): void
    {
        $this->validateTypeDecodingList('double', $this->doubles);
    }

    public function testFloats(): void
    {
        $this->validateTypeDecodingList('float', $this->floats);
    }

    public function testInt32(): void
    {
        $this->validateTypeDecodingList('int32', $this->int32);
    }

    public function testMaps(): void
    {
        $this->validateTypeDecodingList('map', $this->maps);
    }

    public function testPointers(): void
    {
        $this->validateTypeDecodingList('pointers', $this->pointers());
    }

    public function testStrings(): void
    {
        $this->validateTypeDecodingList('utf8_string', $this->strings());
    }

    public function testUint16(): void
    {
        $this->validateTypeDecodingList('uint16', $this->uint16);
    }

    public function testUint32(): void
    {
        $this->validateTypeDecodingList('uint32', $this->uint32());
    }

    public function testUint64(): void
    {
        $this->validateTypeDecoding('uint64', $this->generateLargeUint(64));
    }

    public function testUint128(): void
    {
        $this->validateTypeDecoding('uint128', $this->generateLargeUint(128));
    }

    private function validateTypeDecoding(string $type, array $tests): void
    {
        foreach ($tests as $expected => $input) {
            $this->checkDecoding($type, $input, $expected);
        }
    }

    private function validateTypeDecodingList(string $type, array $tests): void
    {
        foreach ($tests as $test) {
            $this->checkDecoding(
                $type,
                $test['input'],
                $test['expected'],
                $test['name'] ?? $test['input']
            );
        }
    }

    // @phpstan-ignore-next-line
    private function checkDecoding(string $type, array $input, $expected, $name = null): void
    {
        $name = $name || $expected;
        $description = "decoded $type - $name";
        $handle = fopen('php://memory', 'rwb');

        foreach ($input as $byte) {
            fwrite($handle, \chr($byte));
        }
        fseek($handle, 0);
        $decoder = new Decoder($handle, 0, true);
        [$actual] = $decoder->decode(0);

        if ($type === 'float') {
            $actual = round($actual, 2);
        }

        $this->assertSame($expected, $actual, $description);
    }
}
