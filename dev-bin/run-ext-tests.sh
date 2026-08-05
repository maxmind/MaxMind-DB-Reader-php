#!/usr/bin/env bash
#
# Run the extension's phpt suite and assert it ran and passed.
#
# `make test` cannot be trusted on its own here. The Makefile phpize generates
# ends its `test` target with an echo and no `exit 1`, so it succeeds having
# executed nothing when there is no CLI sapi; on 7.2 and 7.3 it does not
# propagate run-tests.php's status at all. run-tests.php in turn counts SKIPPED
# as a pass, and the phpt files that check the extension skip when it is not
# loaded -- so a build producing an unloadable object could otherwise go green.
#
# Run from the directory holding the generated Makefile (ext/).
#
# Shared with maxmind/MaxMind-DB-Reader-php-ext, which builds the same
# extension and needs the same assertion, for the reason dev-bin/
# gate-extension.sh gives for sharing the gate.

set -euo pipefail

fail() {
    echo "::error::$*"
    exit 1
}

log="$(mktemp)"
trap 'rm -f "$log"' EXIT

NO_INTERACTION=1 make test 2>&1 | tee "$log"

# Reads one count out of run-tests.php's summary block. Empty means the line is
# missing, which is a parse failure rather than a zero.
count() { sed -n "s/^Tests $1 *: *\([0-9]*\).*/\1/p" "$log" | tail -n1; }

if grep -q "Cannot run tests without CLI sapi" "$log"; then
    fail "make test ran no tests: no CLI sapi was found"
fi
if ! grep -qE "Tests +(passed|failed)" "$log"; then
    fail "make test produced no test summary; it likely ran nothing"
fi

passed="$(count passed)"
failed="$(count failed)"
warned="$(count warned)"
[ -n "$passed" ] && [ -n "$failed" ] && [ -n "$warned" ] ||
    fail "could not read the pass/fail/warn counts from the make test summary"

# Failures are what matters, and `make test` will not report them for us: the
# summary prints regardless, and the exit status is unreliable on 7.2 and 7.3.
[ "$failed" -eq 0 ] || fail "make test had $failed failing test(s)."
[ "$warned" -eq 0 ] || fail "make test had $warned warned test(s)."
# And a run where everything skipped is not a pass.
[ "$passed" -ge 1 ] ||
    fail "make test passed $passed tests; the extension probably did not load"

echo "make test passed $passed tests, $failed failed, $warned warned."
