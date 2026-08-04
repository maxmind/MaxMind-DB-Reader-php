#!/usr/bin/env bash
#
# Run the extension's phpt suite and assert it actually ran.
#
# The Makefile phpize generates ends its `test` target with an echo and no
# `exit 1`, so `make test` succeeds having executed nothing when there is no
# CLI sapi. run-tests.php also counts SKIPPED as a pass, and two of the three
# phpt files skip when the extension is not loaded -- so a build that produces
# an unloadable object could otherwise go green.
#
# Run from the directory holding the generated Makefile (ext/).
#
# Shared with maxmind/MaxMind-DB-Reader-php-ext, which builds the same
# extension and needs the same assertion, for the reason dev-bin/
# gate-extension.sh gives for sharing the gate.

set -euo pipefail

log="$(mktemp)"
trap 'rm -f "$log"' EXIT

NO_INTERACTION=1 make test 2>&1 | tee "$log"

fail() {
    echo "::error::$*"
    exit 1
}

if grep -q "Cannot run tests without CLI sapi" "$log"; then
    fail "make test ran no tests: no CLI sapi was found"
fi
if ! grep -qE "Tests +(passed|failed)" "$log"; then
    fail "make test produced no test summary; it likely ran nothing"
fi

# The summary prints even when every test skips, so read the count.
passed="$(sed -n 's/^Tests passed *: *\([0-9]*\).*/\1/p' "$log" | tail -n1)"
[ -n "$passed" ] || fail "could not read the passed count from the make test summary"
[ "$passed" -ge 1 ] ||
    fail "make test passed $passed tests; the extension probably did not load"

echo "make test passed $passed tests."
