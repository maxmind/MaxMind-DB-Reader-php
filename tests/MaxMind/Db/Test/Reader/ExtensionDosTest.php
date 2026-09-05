<?php

declare(strict_types=1);

namespace MaxMind\Db\Test\Reader;

use MaxMind\Db\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use PHPUnit\Framework\TestCase;

/**
 * DoS-fixture checks for the C extension's libmaxminddb decoder.
 *
 * The DoS tests in ReaderTest cover the pure-PHP decoder. When the maxminddb
 * extension is loaded, Reader decodes through libmaxminddb instead, which has
 * its own copy of the limits. These checks assert that path rejects the DoS
 * fixtures. They run only when the linked libmaxminddb enforces the limits and
 * skip otherwise; see setUp.
 *
 * @coversNothing
 *
 * @internal
 */
class ExtensionDosTest extends TestCase
{
    // The patched libmaxminddb reports its decoder resource limits through this
    // text (MMDB_DECODER_LIMIT_ERROR). A libmaxminddb without the fix decodes
    // the DoS fixtures instead, so these checks skip rather than run the
    // extension's decoder out of memory.
    private const LIMIT_MESSAGE = 'exceeds the configured resource limits';

    protected function setUp(): void
    {
        if (!\extension_loaded('maxminddb')) {
            $this->markTestSkipped('maxminddb extension not loaded');
        }

        // Probe with a fixture one byte over the 2 MiB payload limit. A patched
        // libmaxminddb rejects it with the decoder-limit message. An older one
        // decodes it, which is only about 2 MiB and so safe, but means the
        // large DoS fixtures below would exhaust memory, so skip instead of
        // running them. Any other error is a real failure and propagates.
        try {
            $this->lookup('MaxMind-DB-test-decoder-payload-limit-over.mmdb');
        } catch (InvalidDatabaseException $e) {
            if (!str_contains($e->getMessage(), self::LIMIT_MESSAGE)) {
                throw $e;
            }

            return;
        }

        // The linked libmaxminddb has no decoder limits. That is expected of an
        // older system library. It is a regression when the build is known to
        // carry the fix, such as a bundled build of the pinned
        // ext/libmaxminddb, so CI sets this variable for those builds to turn
        // the skip into a failure.
        if (getenv('MAXMINDDB_EXPECT_DECODER_LIMITS') !== false) {
            $this->fail(
                'the linked libmaxminddb decoded a fixture that its decoder '
                . 'resource limits must reject'
            );
        }
        $this->markTestSkipped(
            'linked libmaxminddb predates the decoder resource limits '
            . '(needs the fix that adds MMDB_DECODER_LIMIT_ERROR)'
        );
    }

    public function testPointerFanOutFixtureIsRejected(): void
    {
        // A record that nests arrays of pointers to the level below, the
        // classic 2**depth fan-out.
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage(self::LIMIT_MESSAGE);
        $this->lookup('MaxMind-DB-test-pointer-decoder-dos.mmdb');
    }

    public function testIpv6PointerFanOutFixtureIsRejected(): void
    {
        // The same fan-out in a conventional IPv6 database.
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage(self::LIMIT_MESSAGE);
        $this->lookup('MaxMind-DB-test-pointer-decoder-dos-ipv6.mmdb', '::1');
    }

    public function testValueCountBoundaryIsEnforced(): void
    {
        // Exactly at the limit decodes, including through a pointer fan-out;
        // one value over is rejected.
        $this->assertIsArray($this->lookup('MaxMind-DB-test-decoder-value-limit.mmdb'));
        $this->assertIsArray($this->lookup('MaxMind-DB-test-decoder-value-limit-pointer-heavy.mmdb'));

        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage(self::LIMIT_MESSAGE);
        $this->lookup('MaxMind-DB-test-decoder-value-limit-over.mmdb');
    }

    public function testPayloadAmplificationIsRejected(): void
    {
        // An array of pointers to one large value.
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage(self::LIMIT_MESSAGE);
        $this->lookup('MaxMind-DB-test-payload-amplification-dos.mmdb');
    }

    public function testStringPayloadAmplificationIsRejected(): void
    {
        // The UTF-8 string variant, so the string decode path is exercised.
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage(self::LIMIT_MESSAGE);
        $this->lookup('MaxMind-DB-test-payload-amplification-dos-string.mmdb');
    }

    public function testWorstCasePayloadAmplificationIsRejected(): void
    {
        // The largest fan-out that stays under the value limit.
        $this->expectException(InvalidDatabaseException::class);
        $this->expectExceptionMessage(self::LIMIT_MESSAGE);
        $this->lookup('MaxMind-DB-test-payload-amplification-dos-worst-case.mmdb');
    }

    /**
     * Look up one address, which each DoS fixture resolves to its single
     * crafted record.
     *
     * @return mixed the decoded record
     */
    private function lookup(string $fileName, string $ipAddress = '1.1.1.1')
    {
        $reader = new Reader('tests/data/test-data/' . $fileName);

        try {
            return $reader->get($ipAddress);
        } finally {
            $reader->close();
        }
    }
}
