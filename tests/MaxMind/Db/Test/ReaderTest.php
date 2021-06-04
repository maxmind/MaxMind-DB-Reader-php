<?php

declare(strict_types=1);

namespace MaxMind\Db\Test\Reader;

use ArgumentCountError;
use BadMethodCallException;
use InvalidArgumentException;
use MaxMind\Db\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @coversNothing
 *
 * @internal
 */
class ReaderTest extends TestCase
{
    public function testReader(): void
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

    public function testDecoder(): void
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
        $this->assertSame(\PHP_INT_MAX > 1152921504606846976 ? 1152921504606846976 : '1152921504606846976', $record['uint64']);

        $uint128 = $record['uint128'];

        // For the C extension, which returns a hexadecimal
        if (\extension_loaded('gmp')) {
            $uint128 = gmp_strval($uint128);
        } else {
            $this->markTestIncomplete('Requires gmp extension to check value of uint128');
        }

        $this->assertSame(
            '1329227995784915872903807060280344576',
            $uint128
        );
    }

    public function testZeros(): void
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-decoder.mmdb');
        $record = $reader->get('::');

        $this->assertFalse($record['boolean']);
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
        if (\extension_loaded('gmp')) {
            $uint128 = gmp_strval($uint128);
        } else {
            $this->markTestIncomplete('Requires gmp extension to check value of uint128');
        }
        $this->assertSame('0', $uint128);
    }

    public function testMax(): void
    {
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-decoder.mmdb');
        $record = $reader->get('::255.255.255.255');

        $this->assertSame(\INF, $record['double']);
        $this->assertSame(\INF, $record['float'], 'float');
        $this->assertSame(2147483647, $record['int32']);
        $this->assertSame(0xFFFF, $record['uint16']);
        $this->assertSame(\PHP_INT_MAX < 0xFFFFFFFF ? '4294967295' : 0xFFFFFFFF, $record['uint32']);
        $this->assertSame('18446744073709551615', $record['uint64'] . '');

        $uint128 = $record['uint128'];
        if (\extension_loaded('gmp')) {
            $uint128 = gmp_strval($uint128);
        } else {
            $this->markTestIncomplete('Requires gmp extension to check value of uint128');
        }
        $this->assertSame('340282366920938463463374607431768211455', $uint128);
    }

    public function testMetadataPointers(): void
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-metadata-pointers.mmdb'
        );
        $this->assertSame('Lots of pointers in metadata', $reader->metadata()->databaseType);
    }

    public function testNoIpV4SearchTree(): void
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-no-ipv4-search-tree.mmdb'
        );
        $this->assertSame('::0/64', $reader->get('1.1.1.1'));
        $this->assertSame('::0/64', $reader->get('192.1.1.1'));
    }

    public function testGetWithPrefixLen(): void
    {
        $decoderRecord = [
            'array' => [1, 2, 3],
            'boolean' => true,
            'bytes' => pack('N', 42),
            'double' => 42.123456,
            'float' => 1.100000023841858,
            'int32' => -268435456,
            'map' => [
                'mapX' => [
                    'arrayX' => [7, 8, 9],
                    'utf8_stringX' => 'hello',
                ],
            ],
            'uint128' => \extension_loaded('maxminddb') ? '0x01000000000000000000000000000000' : '1329227995784915872903807060280344576',
            'uint16' => 0x64,
            'uint32' => 268435456,
            'uint64' => \PHP_INT_MAX > 1152921504606846976 ? 1152921504606846976 : '1152921504606846976',
            'utf8_string' => 'unicode! ☯ - ♫',
        ];
        $tests = [
            [
                'ip' => '1.1.1.1',
                'dbFile' => 'MaxMind-DB-test-ipv6-32.mmdb',
                'expectedPrefixLength' => 8,
                'expectedRecord' => null,
            ],
            [
                'ip' => '::1:ffff:ffff',
                'dbFile' => 'MaxMind-DB-test-ipv6-24.mmdb',
                'expectedPrefixLength' => 128,
                'expectedRecord' => ['ip' => '::1:ffff:ffff'],
            ],
            [
                'ip' => '::2:0:1',
                'dbFile' => 'MaxMind-DB-test-ipv6-24.mmdb',
                'expectedPrefixLength' => 122,
                'expectedRecord' => ['ip' => '::2:0:0'],
            ],
            [
                'ip' => '1.1.1.1',
                'dbFile' => 'MaxMind-DB-test-ipv4-24.mmdb',
                'expectedPrefixLength' => 32,
                'expectedRecord' => ['ip' => '1.1.1.1'],
            ],
            [
                'ip' => '1.1.1.3',
                'dbFile' => 'MaxMind-DB-test-ipv4-24.mmdb',
                'expectedPrefixLength' => 31,
                'expectedRecord' => ['ip' => '1.1.1.2'],
            ],
            [
                'ip' => '1.1.1.3',
                'dbFile' => 'MaxMind-DB-test-decoder.mmdb',
                'expectedPrefixLength' => 24,
                'expectedRecord' => $decoderRecord,
            ],
            [
                'ip' => '::ffff:1.1.1.128',
                'dbFile' => 'MaxMind-DB-test-decoder.mmdb',
                'expectedPrefixLength' => 120,
                'expectedRecord' => $decoderRecord,
            ],
            [
                'ip' => '::1.1.1.128',
                'dbFile' => 'MaxMind-DB-test-decoder.mmdb',
                'expectedPrefixLength' => 120,
                'expectedRecord' => $decoderRecord,
            ],
            [
                'ip' => '200.0.2.1',
                'dbFile' => 'MaxMind-DB-no-ipv4-search-tree.mmdb',
                'expectedPrefixLength' => 0,
                'expectedRecord' => '::0/64',
            ],
            [
                'ip' => '::200.0.2.1',
                'dbFile' => 'MaxMind-DB-no-ipv4-search-tree.mmdb',
                'expectedPrefixLength' => 64,
                'expectedRecord' => '::0/64',
            ],
            [
                'ip' => '0:0:0:0:ffff:ffff:ffff:ffff',
                'dbFile' => 'MaxMind-DB-no-ipv4-search-tree.mmdb',
                'expectedPrefixLength' => 64,
                'expectedRecord' => '::0/64',
            ],
            [
                'ip' => 'ef00::',
                'dbFile' => 'MaxMind-DB-no-ipv4-search-tree.mmdb',
                'expectedPrefixLength' => 1,
                'expectedRecord' => null,
            ],
        ];

        foreach ($tests as $test) {
            $reader = new Reader('tests/data/test-data/' . $test['dbFile']);
            [$record, $prefixLen] = $reader->getWithPrefixLen($test['ip']);
            $this->assertSame($test['expectedPrefixLength'], $prefixLen, "prefix length for {$test['ip']} on {$test['dbFile']}");
            $this->assertSame($test['expectedRecord'], $record, "record for {$test['ip']} on {$test['dbFile']}");
        }
    }

    public function testV6AddressV4Database(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Error looking up 2001::. You attempted to look up an IPv6 address in an IPv4-only database');
        // @phpstan-ignore-next-line
        if (\defined('MaxMind\\Db\\Reader::MMDB_LIB_VERSION') && version_compare(Reader::MMDB_LIB_VERSION, '1.2.0', '<')) {
            $this->markTestSkipped('MMDB_LIB_VERSION < 1.2.0');
        }
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-ipv4-24.mmdb');
        $reader->get('2001::');
    }

    public function testIpValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The value "not_ip" is not a valid IP address.');
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-decoder.mmdb');
        $reader->get('not_ip');
    }

    public function testBrokenDatabase(): void
    {
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage('The MaxMind DB file\'s data section contains bad data (unknown data type or corrupt data)');
        $reader = new Reader('tests/data/test-data/GeoIP2-City-Test-Broken-Double-Format.mmdb');
        $reader->get('2001:220::');
    }

    public function testBrokenSearchTreePointer(): void
    {
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage('The MaxMind DB file\'s search tree is corrupt');
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-broken-pointers-24.mmdb');
        $reader->get('1.1.1.32');
    }

    public function testBrokenDataPointer(): void
    {
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage('contains bad data');
        $reader = new Reader('tests/data/test-data/MaxMind-DB-test-broken-pointers-24.mmdb');
        $reader->get('1.1.1.16');
    }

    public function testMissingDatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The file "file-does-not-exist.mmdb" does not exist or is not readable.');
        new Reader('file-does-not-exist.mmdb');
    }

    public function testNonDatabase(): void
    {
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage('Error opening database file (README.md). Is this a valid MaxMind DB file?');
        new Reader('README.md');
    }

    public function testTooManyConstructorArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('MaxMind\Db\Reader::__construct() expects exactly 1');
        new Reader('README.md', 1);
    }

    /**
     * This test only matters for the extension.
     */
    public function testNoConstructorArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        // @phpstan-ignore-next-line
        new Reader();
    }

    public function testTooManyGetArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('MaxMind\Db\Reader::get() expects exactly 1');
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->get('1.1.1.1', 'blah');
    }

    /**
     * This test only matters for the extension.
     */
    public function testNoGetArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        // @phpstan-ignore-next-line
        $reader->get();
    }

    public function testMetadataArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('MaxMind\Db\Reader::metadata() expects exactly 0');
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->metadata('blah');
    }

    public function testClose(): void
    {
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();

        $this->assertTrue(true);
    }

    public function testCloseArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('MaxMind\Db\Reader::close() expects exactly 0');
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close('blah');
    }

    public function testDoubleClose(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Attempt to close a closed MaxMind DB.');
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();
        $reader->close();
    }

    public function testClosedGet(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Attempt to read from a closed MaxMind DB.');
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();
        $reader->get('1.1.1.1');
    }

    public function testClosedMetadata(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Attempt to read from a closed MaxMind DB.');
        $reader = new Reader(
            'tests/data/test-data/MaxMind-DB-test-decoder.mmdb'
        );
        $reader->close();
        $reader->metadata();
    }

    public function testReaderIsNotFinal(): void
    {
        $reflectionClass = new ReflectionClass(Reader::class);
        $this->assertFalse($reflectionClass->isFinal());
    }

    private function checkMetadata(Reader $reader, int $ipVersion, int $recordSize): void
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

    private function checkIpV4(Reader $reader, string $fileName): void
    {
        for ($i = 0; $i <= 5; ++$i) {
            $address = '1.1.1.' . 2 ** $i;
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
    private function checkIpV6(Reader $reader, string $fileName): void
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
