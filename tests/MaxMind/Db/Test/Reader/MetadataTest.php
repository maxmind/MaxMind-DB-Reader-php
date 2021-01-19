<?php

declare(strict_types=1);

namespace MaxMind\Db\Test\Reader;

use ArgumentCountError;
use MaxMind\Db\Reader\Metadata;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 *
 * @internal
 */
class MetadataTest extends TestCase
{
    public function testConstructor(): void
    {
        $languages = ['de', 'en', 'es', 'fr', 'ja', 'pt-BR', 'ru', 'zh-CN'];
        $metadata = new Metadata([
            'node_count' => 665037,
            'record_size' => 24,
            'ip_version' => 6,
            'binary_format_major_version' => 2,
            'binary_format_minor_version' => 0,
            'build_epoch' => 1594066370,
            'database_type' => 'GeoIP2-Country',
            'languages' => $languages,
            'description' => ['en' => 'GeoIP2 Country database'],
        ]);

        $this->assertSame($metadata->binaryFormatMajorVersion, 2);
        $this->assertSame($metadata->binaryFormatMinorVersion, 0);
        $this->assertSame($metadata->buildEpoch, 1594066370);
        $this->assertSame($metadata->databaseType, 'GeoIP2-Country');
        $this->assertSame($metadata->description, ['en' => 'GeoIP2 Country database']);
        $this->assertSame($metadata->ipVersion, 6);
        $this->assertSame($metadata->languages, $languages);
        $this->assertSame($metadata->nodeByteSize, 6);
        $this->assertSame($metadata->nodeCount, 665037);
        $this->assertSame($metadata->recordSize, 24);
        $this->assertSame($metadata->searchTreeSize, 6 * 665037);
    }

    public function testTooManyConstructorArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('MaxMind\Db\Reader\Metadata::__construct() expects exactly 1');
        new Metadata([], 1);
    }

    /**
     * This test only matters for the extension.
     */
    public function testNoConstructorArgs(): void
    {
        $this->expectException(ArgumentCountError::class);
        // @phpstan-ignore-next-line
        new Metadata();
    }
}
