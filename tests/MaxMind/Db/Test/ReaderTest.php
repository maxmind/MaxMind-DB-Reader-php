<?php

namespace MaxMind\Db\Test\Reader;

use MaxMind\Db\Reader;

class ReaderTest extends \PHPUnit_Framework_TestCase
{
    public function testReader()
    {
        foreach (array(24, 28, 32) as $recordSize) {
            foreach (array(4, 6) as $ipVersion) {
                $fileName = 'maxmind-db/test-data/MaxMind-DB-test-ipv'
                    . $ipVersion . '-' . $recordSize . '.mmdb';
                $reader = new Reader($fileName);

                $this->checkMetadata($reader, $ipVersion, $recordSize);

                if ($ipVersion == 4) {
                    $this->checkIpV4($reader, $fileName);
                } else {
                    $this->checkIpV6($reader, $fileName);
                }
            }
        }
    }

    public function testDecoder()
    {
        $reader = new Reader('maxmind-db/test-data/MaxMind-DB-test-decoder.mmdb');
        $record = $reader->get('::1.1.1.0');

        $this->assertEquals(array(1,2,3), $record['array']);
        $this->assertEquals(true, $record['boolean']);
        $this->assertEquals(pack('N', 42), $record['bytes']);
        $this->assertEquals(42.123456, $record['double']);
        $this->assertEquals(1.1000000238419, $record['float']);
        $this->assertEquals(-268435456, $record['int32']);
        $this->assertEquals(
            array(
                'mapX' => array(
                    'arrayX' => array(7, 8,9),
                    'utf8_stringX' => 'hello'
                    ),
                ),
            $record['map']
        );
        $this->assertEquals(
            '1329227995784915872903807060280344576',
            $record['uint128']
        );

        $this->assertEquals(100, $record['uint16']);
        $this->assertEquals(268435456, $record['uint32']);
        $this->assertEquals('1152921504606846976', $record['uint64']);
        $this->assertEquals('unicode! ☯ - ♫', $record['utf8_string']);
    }

    private function checkMetadata($reader, $ipVersion, $recordSize)
    {
        $metadata = $reader->metadata();

        $this->assertEquals(
            2,
            $metadata->binaryFormatMajorVersion,
            'major version'
        );
        $this->assertEquals(0, $metadata->binaryFormatMinorVersion);
        $this->assertEquals($ipVersion, $metadata->ipVersion);
        $this->assertEquals('Test', $metadata->databaseType);
        $this->assertEquals('en', $metadata->languages[0]);
        $this->assertEquals('zh', $metadata->languages[1]);

        $this->assertEquals(
            array('en' => 'Test Database', 'zh' => 'Test Database Chinese'),
            $metadata->description
        );
        $this->assertEquals($recordSize, $metadata->recordSize);
    }

    private function checkIpV4(Reader $reader, $fileName)
    {
        for ($i = 0; $i <= 5; $i++) {
            $address = '1.1.1.' . pow(2, $i);
            $this->assertEquals(
                array('ip' => $address),
                $reader->get($address),
                'found expected data record for '
                . $address . ' in ' . $fileName
            );
        }

        $pairs = array(
            '1.1.1.3' => '1.1.1.2',
            '1.1.1.5' => '1.1.1.4',
            '1.1.1.7' => '1.1.1.4',
            '1.1.1.9' => '1.1.1.8',
            '1.1.1.15' => '1.1.1.8',
            '1.1.1.17' => '1.1.1.16',
            '1.1.1.31' => '1.1.1.16'
        );
        foreach ($pairs as $keyAddress => $valueAddress) {
            $data = array('ip' => $valueAddress);

            $this->assertEquals(
                $data,
                $reader->get($keyAddress),
                'found expected data record for ' . $keyAddress . ' in '
                . $fileName
            );
        }

        foreach (array('1.1.1.33', '255.254.253.123') as $ip) {
            $this->assertNull($reader->get($ip));
        }
    }

        // XXX - logic could be combined with above
    private function checkIpV6(Reader $reader, $fileName)
    {
        $subnets = array( '::1:ffff:ffff', '::2:0:0',
                '::2:0:40', '::2:0:50', '::2:0:58' );

        foreach ($subnets as $address) {
            $this->assertEquals(
                array('ip' => $address),
                $reader->get($address),
                'found expected data record for ' . $address . ' in '
                . $fileName
            );
        }

        $pairs = array(
            '::2:0:1' => '::2:0:0',
            '::2:0:33' => '::2:0:0',
            '::2:0:39' => '::2:0:0',
            '::2:0:41' => '::2:0:40',
            '::2:0:49' => '::2:0:40',
            '::2:0:52' => '::2:0:50',
            '::2:0:57' => '::2:0:50',
            '::2:0:59' => '::2:0:58'
        );

        foreach ($pairs as $keyAddress => $valueAddress) {
            $this->assertEquals(
                array('ip' => $valueAddress),
                $reader->get($keyAddress),
                'found expected data record for ' + $keyAddress + ' in '
                + $fileName
            );
        }

        foreach (array('1.1.1.33', '255.254.253.123', '89fa::') as $ip) {
            $this->assertNull($reader->get($ip));
        }
    }
}
