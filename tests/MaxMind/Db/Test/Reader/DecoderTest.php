<?php

namespace MaxMind\Db\Test\Reader;

use MaxMind\Db\Reader\Decoder;

class DecoderTest extends \PHPUnit_Framework_TestCase
{

    private $floats = array(
        '0.0' => array(0x4, 0x8, 0x0, 0x0, 0x0, 0x0),
        '1.0' => array(0x4, 0x8, 0x3F, 0x80, 0x0, 0x0),
        '1.1' => array(0x4, 0x8, 0x3F, 0x8C, 0xCC, 0xCD),
        '3.14' => array(0x4, 0x8, 0x40, 0x48, 0xF5, 0xC3),
        '9999.99' => array(0x4, 0x8, 0x46, 0x1C, 0x3F, 0xF6),
        '-1.0' => array(0x4, 0x8, 0xBF, 0x80, 0x0, 0x0),
        '-1.1' => array(0x4, 0x8,  0xBF, 0x8C, 0xCC, 0xCD),
        '-3.14' => array(0x4, 0x8,  0xC0, 0x48, 0xF5, 0xC3),
        '-9999.99' => array( 0x4, 0x8,  0xC6, 0x1C, 0x3F,  0xF6 )
    );

    public function testFloats()
    {
        $this->validateTypeDecoding('float', $this->floats);
    }

    private function validateTypeDecoding($type, $tests)
    {

        foreach ($tests as $expected => $input) {

            $description = "decoded $type - $expected";
            $handle = fopen('php://memory', 'rw');

            foreach ($input as $byte) {
                fwrite($handle, pack('C', $byte));
            }
            // XXX - debugging
            fseek($handle, 0);
            print_r(unpack('C*', fread($handle, 6)));

            fseek($handle, 0);

            $decoder = new Decoder($handle);
            $decoder->POINTER_TEST_HACK = true;
            list($actual) = $decoder->decode(0);

            if ($type == 'float') {
                $actual = round($actual, 2);
            }

            $this->assertEquals($expected, $actual, $description);
        }
    }
}
