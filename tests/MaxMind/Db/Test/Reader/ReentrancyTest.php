<?php

declare(strict_types=1);

namespace MaxMind\Db\Test\Reader;

use MaxMind\Db\Reader;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 *
 * @internal
 */
class ReentrancyTest extends TestCase
{
    public function testReentrantLookupIsRejected(): void
    {
        $this->checkReentrantLookup(false);
    }

    public function testCaughtReentrantLookupDoesNotCorruptRecord(): void
    {
        $this->checkReentrantLookup(true);
    }

    private function checkReentrantLookup(bool $catchRejection): void
    {
        if (\extension_loaded('maxminddb')) {
            $this->markTestSkipped('the extension does not open PHP stream wrappers');
        }

        $file = 'tests/data/test-data/GeoIP2-City-Test.mmdb';
        $data = file_get_contents($file);
        $this->assertIsString($data);
        ReentrantStream::$data = $data;
        $plainReader = new Reader($file);
        $expected = $plainReader->get('81.2.69.160');
        $plainReader->close();

        $this->assertTrue(stream_wrapper_register('mmdbreentrant', ReentrantStream::class));
        $reader = null;

        try {
            $reader = new Reader('mmdbreentrant://db');
            $dataStart = $reader->metadata()->searchTreeSize + 16;
            $rejected = false;
            ReentrantStream::$onRead = function (int $offset) use ($reader, $catchRejection, $dataStart, &$rejected): void {
                if ($offset < $dataStart) {
                    return;
                }
                // Reenter during a read from the data section. Disable the callback
                // before the nested lookup so the test cannot recurse forever.
                ReentrantStream::$onRead = null;

                try {
                    $reader->get('216.160.83.56');
                } catch (\BadMethodCallException $e) {
                    $this->assertStringContainsString('A lookup is already in progress', $e->getMessage());
                    $rejected = true;
                    if (!$catchRejection) {
                        throw $e;
                    }
                }
            };

            try {
                $actual = $reader->getWithPrefixLen('81.2.69.160');
                $this->assertTrue($catchRejection, 'the nested lookup must throw');
                $this->assertSame($expected, $actual[0]);
            } catch (\BadMethodCallException $e) {
                $this->assertFalse($catchRejection);
                $this->assertStringContainsString('A lookup is already in progress', $e->getMessage());
            }
            $this->assertTrue($rejected, 'the stream callback must attempt a nested lookup');
            $this->assertSame($expected, $reader->get('81.2.69.160'));
        } finally {
            ReentrantStream::$onRead = null;
            ReentrantStream::$data = '';
            if ($reader !== null) {
                $reader->close();
            }
            $this->assertTrue(stream_wrapper_unregister('mmdbreentrant'));
        }
    }
}
