<?php

declare(strict_types=1);

namespace MaxMind\Db\Reader;

use ArgumentCountError;

/**
 * This class provides the metadata for the MaxMind DB file.
 *
 * @property int    $nodeCount                This is an unsigned 32-bit
 *                                            integer indicating the number of
 *                                            nodes in the search tree.
 * @property int    $recordSize               This is an unsigned 16-bit
 *                                            integer. It indicates the number
 *                                            of bits in a record in the search
 *                                            tree. Note that each node
 *                                            consists of two records.
 * @property int    $ipVersion                This is an unsigned 16-bit
 *                                            integer which is always 4 or 6.
 *                                            It indicates whether the database
 *                                            contains IPv4 or IPv6 address
 *                                            data.
 * @property string $databaseType             This is a string that indicates
 *                                            the structure of each data record
 *                                            associated with an IP address.
 *                                            The actual definition of these
 *                                            structures is left up to the
 *                                            database creator.
 * @property array  $languages                An array of strings, each of
 *                                            which is a language code. A given
 *                                            record may contain data items
 *                                            that have been localized to some
 *                                            or all of these languages. This
 *                                            may be undefined.
 * @property int    $binaryFormatMajorVersion This is an unsigned 16-bit
 *                                            integer indicating the major
 *                                            version number for the database's
 *                                            binary format.
 * @property int    $binaryFormatMinorVersion This is an unsigned 16-bit
 *                                            integer indicating the minor
 *                                            version number for the database's
 *                                            binary format.
 * @property int    $buildEpoch               This is an unsigned 64-bit
 *                                            integer that contains the
 *                                            database build timestamp as a
 *                                            Unix epoch value.
 * @property array  $description              This key will always point to a
 *                                            map (associative array). The keys
 *                                            of that map will be language
 *                                            codes, and the values will be a
 *                                            description in that language as a
 *                                            UTF-8 string. May be undefined
 *                                            for some databases.
 */
class Metadata
{
    /**
     * @var int
     */
    private $binaryFormatMajorVersion;
    /**
     * @var int
     */
    private $binaryFormatMinorVersion;
    /**
     * @var int
     */
    private $buildEpoch;
    /**
     * @var string
     */
    private $databaseType;
    /**
     * @var array
     */
    private $description;
    /**
     * @var int
     */
    private $ipVersion;
    /**
     * @var array
     */
    private $languages;
    /**
     * @var int
     */
    private $nodeByteSize;
    /**
     * @var int
     */
    private $nodeCount;
    /**
     * @var int
     */
    private $recordSize;
    /**
     * @var int
     */
    private $searchTreeSize;

    public function __construct(array $metadata)
    {
        if (\func_num_args() !== 1) {
            throw new ArgumentCountError(
                sprintf('%s() expects exactly 1 parameter, %d given', __METHOD__, \func_num_args())
            );
        }

        $this->binaryFormatMajorVersion =
            $metadata['binary_format_major_version'];
        $this->binaryFormatMinorVersion =
            $metadata['binary_format_minor_version'];
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

    /**
     * @return mixed
     */
    public function __get(string $var)
    {
        return $this->$var;
    }
}
