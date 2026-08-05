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

// Validated rather than assumed: this script is shared with
// maxmind/MaxMind-DB-Reader-php-ext, where the caller is out of sight. An empty
// $expected against an empty MMDB_LIB_VERSION would compare equal and exit 0
// having proved nothing -- and "" is exactly the value a PACKAGE_VERSION that
// never reached the compiler produces.
if ($argc !== 3) {
    fwrite(\STDERR, "usage: verify-extension.php <mmdb path> <expected MMDB_LIB_VERSION>\n");

    exit(1);
}
if ($argv[2] === '') {
    fwrite(\STDERR, "the expected MMDB_LIB_VERSION must not be empty\n");

    exit(1);
}

// Does not rely on the caller passing -n: with an autoloader in scope the
// pure-PHP Reader would satisfy everything below and prove nothing about the
// object under test.
if (!extension_loaded('maxminddb')) {
    fwrite(\STDERR, "the maxminddb extension is not loaded\n");

    exit(1);
}

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
