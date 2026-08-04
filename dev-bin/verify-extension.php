<?php

declare(strict_types=1);

use MaxMind\Db\Reader;

// Load a maxminddb extension on its own and check it decodes and reports
// itself correctly. Run under `php -n -d extension=<object>` so that no ini
// file can supply anything the object did not bring with it.
//
// Usage: php verify-extension.php <path to GeoIP2-City-Test.mmdb> <expected MMDB_LIB_VERSION>
//
// Shared with maxmind/MaxMind-DB-Reader-php-ext, which needs the same check
// against the binaries it publishes.

$reader = new Reader($argv[1]);
$record = $reader->get('81.2.69.160');
$city = isset($record['city']['names']['en'])
    ? $record['city']['names']['en'] : '';

// Asserting the value rather than merely that one came back: 81.2.69.160 is
// London in GeoIP2-City-Test.mmdb, so this is a correctness check for one
// decoded string instead of a liveness check.
if ($city !== 'London') {
    fwrite(\STDERR, 'expected London, got: ' . var_export($city, true) . "\n");

    exit(1);
}
echo "lookup returned city: {$city}\n";

// MMDB_lib_version() returns the PACKAGE_VERSION the build system defined, so
// this catches a submodule bump that did not update it -- and, on PHP 7.2 and
// 7.3, a define that never reached the compiler at all.
$expected = $argv[2];
$actual = Reader::MMDB_LIB_VERSION;
if ($actual !== $expected) {
    fwrite(\STDERR, "MMDB_LIB_VERSION is {$actual}, expected {$expected}\n");

    exit(1);
}
echo "MMDB_LIB_VERSION: {$actual}\n";
