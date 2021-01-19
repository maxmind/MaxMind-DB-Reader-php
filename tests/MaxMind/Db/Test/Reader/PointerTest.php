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
class PointerTest extends TestCase
{
    public function testWithPointers(): void
    {
        $handle = fopen('tests/data/test-data/maps-with-pointers.raw', 'rb');
        $decoder = new Decoder($handle, 0);

        $this->assertSame(
            [['long_key' => 'long_value1'], 22],
            $decoder->decode(0)
        );

        $this->assertSame(
            [['long_key' => 'long_value2'], 37],
            $decoder->decode(22)
        );

        $this->assertSame(
            [['long_key2' => 'long_value1'], 50],
            $decoder->decode(37)
        );

        $this->assertSame(
            [['long_key2' => 'long_value2'], 55],
            $decoder->decode(50)
        );

        $this->assertSame(
            [['long_key' => 'long_value1'], 57],
            $decoder->decode(55)
        );

        $this->assertSame(
            [['long_key2' => 'long_value2'], 59],
            $decoder->decode(57)
        );
    }
}
