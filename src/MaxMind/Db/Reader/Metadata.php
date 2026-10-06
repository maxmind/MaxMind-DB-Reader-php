<?php

declare(strict_types=1);

namespace MaxMind\Db\Reader;

/**
 * This class provides the metadata for the MaxMind DB file.
 */
class Metadata
{
    /**
     * This is an unsigned 16-bit integer indicating the major version number
     * for the database's binary format.
     *
     * @var int
     */
    public $binaryFormatMajorVersion;

    /**
     * This is an unsigned 16-bit integer indicating the minor version number
     * for the database's binary format.
     *
     * @var int
     */
    public $binaryFormatMinorVersion;

    /**
     * This is an unsigned 64-bit integer that contains the database build
     * timestamp as a Unix epoch value.
     *
     * @var int|string
     */
    public $buildEpoch;

    /**
     * This is a string that indicates the structure of each data record
     * associated with an IP address.  The actual definition of these
     * structures is left up to the database creator.
     *
     * @var string
     */
    public $databaseType;

    /**
     * This key will always point to a map (associative array). The keys of
     * that map will be language codes, and the values will be a description
     * in that language as a UTF-8 string. May be undefined for some
     * databases.
     *
     * @var array<string, string>
     */
    public $description;

    /**
     * This is an unsigned 16-bit integer which is always 4 or 6. It indicates
     * whether the database contains IPv4 or IPv6 address data.
     *
     * @var int
     */
    public $ipVersion;

    /**
     * An array of strings, each of which is a language code. A given record
     * may contain data items that have been localized to some or all of
     * these languages. This may be undefined.
     *
     * @var array<string>
     */
    public $languages;

    /**
     * @var int
     */
    public $nodeByteSize;

    /**
     * This is an unsigned 32-bit integer indicating the number of nodes in
     * the search tree.
     *
     * @var int
     */
    public $nodeCount;

    /**
     * This is an unsigned 16-bit integer. It indicates the number of bits in a
     * record in the search tree. Note that each node consists of two records.
     *
     * @var int
     */
    public $recordSize;

    /**
     * @var int
     */
    public $searchTreeSize;

    /**
     * @param array<string, mixed> $metadata
     *
     * @throws InvalidDatabaseException if a metadata field is missing or invalid
     */
    public function __construct(array $metadata)
    {
        if (\func_num_args() !== 1) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects exactly 1 parameter, %d given', __METHOD__, \func_num_args())
            );
        }

        foreach ([
            'binary_format_major_version',
            'binary_format_minor_version',
            'ip_version',
            'node_count',
            'record_size',
        ] as $key) {
            if (!isset($metadata[$key]) || !\is_int($metadata[$key]) || $metadata[$key] < 0) {
                throw new InvalidDatabaseException("Metadata field $key must be an unsigned integer.");
            }
        }
        $buildEpoch = $metadata['build_epoch'] ?? null;
        if ((!\is_int($buildEpoch) || $buildEpoch < 0)
            && (!\is_string($buildEpoch) || !preg_match('/\A[0-9]+\z/', $buildEpoch))
        ) {
            throw new InvalidDatabaseException('Metadata build_epoch must be an unsigned integer.');
        }
        if (!\in_array($metadata['record_size'], [24, 28, 32], true)) {
            throw new InvalidDatabaseException('Metadata record_size must be 24, 28, or 32.');
        }
        if (!\in_array($metadata['ip_version'], [4, 6], true)) {
            throw new InvalidDatabaseException('Metadata ip_version must be 4 or 6.');
        }
        if (!isset($metadata['database_type']) || !\is_string($metadata['database_type'])) {
            throw new InvalidDatabaseException('Metadata database_type must be a string.');
        }
        foreach (['languages', 'description'] as $key) {
            if (!\array_key_exists($key, $metadata)) {
                $metadata[$key] = [];
            }
            if (!\is_array($metadata[$key])) {
                throw new InvalidDatabaseException("Metadata field $key must be an array of strings.");
            }
            foreach ($metadata[$key] as $value) {
                if (!\is_string($value)) {
                    throw new InvalidDatabaseException("Metadata field $key must contain only strings.");
                }
            }
        }

        $this->binaryFormatMajorVersion
            = $metadata['binary_format_major_version'];
        $this->binaryFormatMinorVersion
            = $metadata['binary_format_minor_version'];
        $this->buildEpoch = $metadata['build_epoch'];
        $this->databaseType = $metadata['database_type'];
        $this->languages = $metadata['languages'];
        $this->description = $metadata['description'];
        $this->ipVersion = $metadata['ip_version'];
        $this->nodeCount = $metadata['node_count'];
        $this->recordSize = $metadata['record_size'];
        $this->nodeByteSize = $this->recordSize / 4;
        $this->searchTreeSize = $this->nodeCount * $this->nodeByteSize;
    }
}
