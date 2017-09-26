<?php

namespace MaxMind\Db\Test\Reader;

use MaxMind\Db\Reader;

/**
 * @coversNothing
 */
class ReaderTest extends \PHPUnit_Framework_TestCase
{
    public function testReader()
    {
        foreach ([24, 28, 32] as $recordSize) {
            foreach ([4, 6] as $ipVersion) {
                $fileName = 'tests/data/test-data/MaxMind-DB-test-ipv'
                    . $ipVersion . '-' . $recordSize . '.mmdb';
                $reader = new Reader($fileName);

                $this->checkMetadata($reader, $ipVersion, $recordSize);

                if ($ipVersion === 4) {
                    $this->checkIpV4($reader, $fileName);
                } else {
                    $this->checkIpV6($reader, $fileName);
                }
            }
        }
    }

    public function testDecoder()
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-decoder.mmdb');
        $record = $reader->get('::1.1.1.0');

        $this->assertTrue($record['boolean']);
        $this->assertSame(pack('N', 42), $record['bytes']);
        $this->assertSame('unicode! ☯ - ♫', $record['utf8_string']);

        $this->assertSame([1, 2, 3], $record['array']);

        $this->assertSame(
            [
                'mapX' => [
                    'arrayX' => [7, 8, 9],
                    'utf8_stringX' => 'hello',
                ],
            ],
            $record['map']
        );

        $this->assertSame(42.123456, $record['double']);
        $this->assertSame(1.1000000238418579, $record['float'], 'float');

        $this->assertSame(-268435456, $record['int32']);
        $this->assertSame(100, $record['uint16']);
        $this->assertSame(268435456, $record['uint32']);
        $this->assertSame('1152921504606846976', $record['uint64']);

        $uint128 = $record['uint128'];

        // For the C extension, which returns a hexadecimal
        if (extension_loaded('gmp')) {
            $uint128 = gmp_strval($uint128);
        } else {
            $this->markTestIncomplete('Requires gmp extension to check value of uint128');
        }

        $this->assertSame(
            '1329227995784915872903807060280344576',
            $uint128
        );
    }

    public function testZeros()
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-decoder.mmdb');
        $record = $reader->get('::');

        $this->assertSame(false, $record['boolean']);
        $this->assertSame('', $record['bytes']);
        $this->assertSame('', $record['utf8_string']);

        $this->assertSame([], $record['array']);
        $this->assertSame([], $record['map']);

        $this->assertSame(0.0, $record['double']);
        $this->assertSame(0.0, $record['float'], 'float');
        $this->assertSame(0, $record['int32']);
        $this->assertSame(0, $record['uint16']);
        $this->assertSame(0, $record['uint32']);
        $this->assertSame('0', $record['uint64'] . '');

        $uint128 = $record['uint128'];
        if (extension_loaded('gmp')) {
            $uint128 = gmp_strval($uint128);
        } else {
            $this->markTestIncomplete('Requires gmp extension to check value of uint128');
        }
        $this->assertSame('0', $uint128);
    }

    public function testNoIpV4SearchTree()
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-no-ipv4-search-tree.mmdb'
        );
        $this->assertSame('::0/64', $reader->get('1.1.1.1'));
        $this->assertSame('::0/64', $reader->get('192.1.1.1'));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Error looking up 2001::. You attempted to look up an IPv6 address in an IPv4-only database
     */
    public function testV6AddressV4Database()
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-ipv4-24.mmdb');
        $reader->get('2001::');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The value "not_ip" is not a valid IP address.
     */
    public function testIpValidation()
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-decoder.mmdb');
        $reader->get('not_ip');
    }

    /**
     * @expectedException \MaxMind\Db\Reader\InvalidDatabaseException
     * @expectedExceptionMessage The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)
     */
    public function testBrokenDatabase()
    {
        $reader = new Reader('tests/data/test-data/GeoIP2-City-Test-Broken-Double-Format.mmdb');
        $reader->get('2001:220::');
    }

    /**
     * @expectedException \MaxMind\Db\Reader\InvalidDatabaseException
     * @expectedExceptionMessage The MaxMind DB file's search tree is corrupt
     */
    public function testBrokenSearchTreePointer()
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-broken-pointers-24.mmdb');
        $reader->get('1.1.1.32');
    }

    /**
     * @expectedException \MaxMind\Db\Reader\InvalidDatabaseException
     * @expectedExceptionMessage contains bad data
     */
    public function testBrokenDataPointer()
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-broken-pointers-24.mmdb');
        $reader->get('1.1.1.16');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The file "file-does-not-exist.mmdb" does not exist or is not readable.
     */
    public function testMissingDatabase()
    {
        new Reader('file-does-not-exist.mmdb');
    }

    /**
     * @expectedException \MaxMind\Db\Reader\InvalidDatabaseException
     * @expectedExceptionMessage Error opening database file (README.md). Is this a valid MaxMind DB file?
     */
    public function testNonDatabase()
    {
        new Reader('README.md');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The constructor takes exactly one argument.
     */
    public function testTooManyConstructorArgs()
    {
        new Reader('README.md', 1);
    }

    /**
     * @expectedException \InvalidArgumentException
     *
     * This test only matters for the extension.
     */
    public function testNoConstructorArgs()
    {
        if (extension_loaded('maxminddb')) {
            new Reader();
        } else {
            throw new \InvalidArgumentException();
        }
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Method takes exactly one argument.
     */
    public function testTooManyGetAgs()
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->get('1.1.1.1', 'blah');
    }

    /**
     * @expectedException \InvalidArgumentException
     *
     * This test only matters for the extension.
     */
    public function testNoGetArgs()
    {
        if (extension_loaded('maxminddb')) {
            $reader = new Reader(
                'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
            );
            $reader->get();
        } else {
            throw new \InvalidArgumentException();
        }
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Method takes no arguments.
     */
    public function testMetadataAgs()
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->metadata('blah');
    }

    public function testClose()
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();
    }

    /**
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessage Attempt to close a closed MaxMind DB.
     */
    public function testDoubleClose()
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();
        $reader->close();
    }

    /**
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessage Attempt to read from a closed MaxMind DB.
     */
    public function testClosedGet()
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();
        $reader->get('1.1.1.1');
    }

    /**
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessage Attempt to read from a closed MaxMind DB.
     */
    public function testClosedMetadata()
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();
        $reader->metadata();
    }

    public function testReaderIsNotFinal()
    {
        $reflectionClass = new \ReflectionClass('MaxMind\Db\Reader');
        $this->assertFalse($reflectionClass->isFinal());
    }

    private function checkMetadata($reader, $ipVersion, $recordSize)
    {
        $metadata = $reader->metadata();

        $this->assertSame(
            2,
            $metadata->binaryFormatMajorVersion,
            'major version'
        );
        $this->assertSame(0, $metadata->binaryFormatMinorVersion);
        $this->assertGreaterThan(1373571901, $metadata->buildEpoch);
        $this->assertSame('Test', $metadata->databaseType);

        $this->assertSame(
            ['en' => 'Test Database', 'zh' => 'Test Database Chinese'],
            $metadata->description
        );

        $this->assertSame($ipVersion, $metadata->ipVersion);
        $this->assertSame(['en', 'zh'], $metadata->languages);
        $this->assertSame($recordSize / 4, $metadata->nodeByteSize);
        $this->assertGreaterThan(36, $metadata->nodeCount);

        $this->assertSame($recordSize, $metadata->recordSize);
        $this->assertGreaterThan(200, $metadata->searchTreeSize);
    }

    private function checkIpV4(Reader $reader, $fileName)
    {
        for ($i = 0; $i <= 5; $i++) {
            $address = '1.1.1.' . pow(2, $i);
            $this->assertSame(
                ['ip' => $address],
                $reader->get($address),
                'found expected data record for '
                . $address . ' in ' . $fileName
            );
        }

        $pairs = [
            '1.1.1.3' => '1.1.1.2',
            '1.1.1.5' => '1.1.1.4',
            '1.1.1.7' => '1.1.1.4',
            '1.1.1.9' => '1.1.1.8',
            '1.1.1.15' => '1.1.1.8',
            '1.1.1.17' => '1.1.1.16',
            '1.1.1.31' => '1.1.1.16',
        ];
        foreach ($pairs as $keyAddress => $valueAddress) {
            $data = ['ip' => $valueAddress];

            $this->assertSame(
                $data,
                $reader->get($keyAddress),
                'found expected data record for ' . $keyAddress . ' in '
                . $fileName
            );
        }

        foreach (['1.1.1.33', '255.254.253.123'] as $ip) {
            $this->assertNull($reader->get($ip));
        }
    }

    // XXX - logic could be combined with above
    private function checkIpV6(Reader $reader, $fileName)
    {
        $subnets = ['::1:ffff:ffff', '::2:0:0',
            '::2:0:40', '::2:0:50', '::2:0:58', ];

        foreach ($subnets as $address) {
            $this->assertSame(
                ['ip' => $address],
                $reader->get($address),
                'found expected data record for ' . $address . ' in '
                . $fileName
            );
        }

        $pairs = [
            '::2:0:1' => '::2:0:0',
            '::2:0:33' => '::2:0:0',
            '::2:0:39' => '::2:0:0',
            '::2:0:41' => '::2:0:40',
            '::2:0:49' => '::2:0:40',
            '::2:0:52' => '::2:0:50',
            '::2:0:57' => '::2:0:50',
            '::2:0:59' => '::2:0:58',
        ];

        foreach ($pairs as $keyAddress => $valueAddress) {
            $this->assertSame(
                ['ip' => $valueAddress],
                $reader->get($keyAddress),
                'found expected data record for ' . $keyAddress . ' in '
                . $fileName
            );
        }

        foreach (['1.1.1.33', '255.254.253.123', '89fa::'] as $ip) {
            $this->assertNull($reader->get($ip));
        }
    }
}
