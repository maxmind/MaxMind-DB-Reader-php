--TEST--
openbase_dir is followed
--SKIPIF--
<?php
// Deliberately not skipped when the extension is missing: 001 and 002 both do
// that, so this is the only test that fails rather than skips when a build
// produces an extension that cannot load.
if (PHP_OS_FAMILY === 'Windows') {
    echo 'skip both paths below are POSIX-specific';
}
?>
--INI--
open_basedir=/--dne--
--FILE--
<?php
use MaxMind\Db\Reader;

$reader = new Reader('/usr/local/share/GeoIP/GeoIP2-City.mmdb');
?>
--EXPECTREGEX--
.*open_basedir restriction in effect.*
