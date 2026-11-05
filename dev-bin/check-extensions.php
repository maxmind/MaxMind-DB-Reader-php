<?php

declare(strict_types=1);

// Check which extensions are loaded. A failed `extension=` load is only a
// warning, and the test suite passes without the C extension or gmp, so CI
// runs this before PHPUnit with the same PHP options.
//
// Usage: php [-d ...] check-extensions.php <name>...
//
// A name that starts with ":" must not be loaded. This is the syntax that
// setup-php uses to disable an extension.

if ($argc < 2) {
    fwrite(\STDERR, "usage: check-extensions.php <name>...\n");

    exit(1);
}

$failed = false;
foreach (array_slice($argv, 1) as $arg) {
    if (substr($arg, 0, 1) === ':') {
        $name = substr($arg, 1);
        if (extension_loaded($name)) {
            echo "::error::{$name} is loaded, but this job must not load it\n";
            $failed = true;
        }
    } elseif (!extension_loaded($arg)) {
        echo "::error::{$arg} is not loaded\n";
        $failed = true;
    }
}

exit($failed ? 1 : 0);
