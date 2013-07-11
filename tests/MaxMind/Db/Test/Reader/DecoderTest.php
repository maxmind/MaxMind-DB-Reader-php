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

    private $pointers = array(
        0 => array(0x20, 0x0),
        5 => array(0x20, 0x5),
        10 => array(0x20, 0xa),
        1023 => array(0x23, 0xff,),
        3017 => array(0x28, 0x3, 0xc9),
        524283 => array(0x2f, 0xf7, 0xfb),
        526335 => array(0x2f, 0xff, 0xff),
        134217726 => array(0x37, 0xf7, 0xf7, 0xfe),
        134744063 => array(0x37, 0xff, 0xff, 0xff),
        2147483647 => array(0x38, 0x7f, 0xff, 0xff, 0xff),
        4294967295=> array(0x38, 0xff, 0xff, 0xff, 0xff),
    );

    private $uint16 = array(
        0 => array(0xa0),
        255 => array(0xa1, 0xff),
        500 => array(0xa2, 0x1, 0xf4),
        10872 => array(0xa2, 0x2a, 0x78),
        65535 => array(0xa2, 0xff, 0xff),
    );


     private $int32 = array(
        '0' => array( 0x0, 0x1),
        '-1' => array( 0x4, 0x1, 0xff, 0xff, 0xff, 0xff),
        '255' => array( 0x1, 0x1, 0xff),
        '-255' => array( 0x4, 0x1, 0xff, 0xff, 0xff, 0x1),
        '500' => array( 0x2, 0x1, 0x1, 0xf4),
        '-500' => array( 0x4, 0x1, 0xff, 0xff, 0xfe, 0xc),
        '65535' => array( 0x2, 0x1, 0xff, 0xff),
        '-65535' => array( 0x4, 0x1, 0xff, 0xff, 0x0, 0x1),
        '16777215' => array( 0x3, 0x1, 0xff, 0xff, 0xff),
        '-16777215' => array( 0x4, 0x1, 0xff, 0x0, 0x0, 0x1),
        '2147483647' => array( 0x4, 0x1, 0x7f, 0xff, 0xff, 0xff),
        '-2147483647' => array( 0x4, 0x1, 0x80, 0x0, 0x0, 0x1),
    );

     private $uint32 = array(
        0 => array(0xc0),
        255 => array(0xc1, 0xff),
        500 => array(0xc2, 0x1, 0xf4),
        10872 => array(0xc2, 0x2a, 0x78),
        65535 => array(0xc2, 0xff, 0xff),
        16777215 => array(0xc3, 0xff, 0xff, 0xff),
        4294967295 => array(0xc4, 0xff, 0xff, 0xff, 0xff),
    );

    public function generateLargeUint($bits)
    {

    }

    public function testFloats()
    {
        $this->validateTypeDecoding('float', $this->floats);
    }

    public function testInt32()
    {
        $this->validateTypeDecoding('int32', $this->int32);
    }

    public function testPointers()
    {
        $this->validateTypeDecoding('pointers', $this->pointers);
    }


    public function testUint16()
    {
        $this->validateTypeDecoding('uint16', $this->uint16);
    }

    public function testUint32()
    {
        $this->validateTypeDecoding('uint32', $this->uint32);
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
