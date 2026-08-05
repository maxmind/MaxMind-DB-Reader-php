#!/usr/bin/env bash
#
# Assert that gate-extension.sh accepts a good object and rejects bad ones.
#
# Every other caller runs the gate over an object it expects to pass, so the
# gate is only ever observed succeeding. Nothing would notice it breaking into
# certifying anything -- or, just as quietly, into refusing everything, which
# a suite of rejection cases alone cannot tell apart from working correctly.
#
# So: a positive control first, and every rejection matched on the message the
# gate is supposed to print rather than on a non-zero exit, since 126 and 127
# are non-zero too and "the gate never ran" must not read as "the gate said no".
#
# Usage: test-gate-extension.sh <path to a known-good extension object>

set -euo pipefail

fail() {
    echo "::error::$*"
    exit 1
}

[ $# -eq 1 ] || fail "Usage: test-gate-extension.sh <path to a known-good extension object>"

so="$1"
[ -f "$so" ] || fail "$so does not exist; nothing to test the gate against."

gate="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/gate-extension.sh"
[ -x "$gate" ] || fail "$gate is missing or not executable."

command -v gcc >/dev/null || fail "gcc is required to build the fixtures this test refutes against."

# The positive control. Without it, a gate broken into always failing satisfies
# every case below.
"$gate" "$so" >/dev/null || fail "the gate rejected the known-good object $so."

refute() { # <expected ::error:: substring> <description> <command...>
    local want="$1" what="$2" out status
    shift 2
    set +e
    out="$("$@" 2>&1)"
    status=$?
    set -e
    [ "$status" -ne 0 ] || fail "the gate accepted $what"
    grep -qF "$want" <<<"$out" || fail \
        "the gate rejected $what, but not for the expected reason: wanted \"$want\", got \"$(grep -m1 '::error::' <<<"$out" || head -n1 <<<"$out")\""
}

# Fixtures for the checks no malformed *input* can reach -- they need an object
# that builds and loads but is wrong in one specific way. Each carries a
# get_module and a libc reference so it clears the earlier checks and reaches
# the one under test.
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cat > "$work/base.c" <<'C'
#include <stdlib.h>
__attribute__((visibility("default"))) void *get_module(void) { return malloc(8); }
C
build() { # <output> <extra source> <extra link args...>
    local out="$1" src="$2"
    shift 2
    cat "$work/base.c" > "$work/tmp.c"
    [ -z "$src" ] || printf '%s\n' "$src" >> "$work/tmp.c"
    gcc -shared -fPIC -fvisibility=hidden -o "$work/$out" "$work/tmp.c" "$@"
}

build exports.so '__attribute__((visibility("default"))) int MMDB_open(void) { return 0; }'
build nogetmodule.so '' -Wl,--version-script=/dev/null 2>/dev/null ||
    gcc -shared -fPIC -fvisibility=hidden -o "$work/nogetmodule.so" \
        -xc - <<<'#include <stdlib.h>
void *get_module(void) { return malloc(8); }'
build runpath.so '' -Wl,--enable-new-dtags,-rpath,/tmp
# Something outside the C runtime to depend on, built here so the test needs no
# development packages installed.
gcc -shared -fPIC -o "$work/libunexpected.so" -xc - <<<'int unexpected(void) { return 0; }'
build extradep.so 'extern int unexpected(void); int use(void) { return unexpected(); }' \
    -L"$work" -lunexpected -Wl,-rpath-link,"$work"

refute "Usage: gate-extension.sh" "a missing argument" "$gate"
refute "does not exist or is not a regular file" "a path that does not exist" \
    "$gate" /nonexistent/maxminddb.so
refute "above the documented maximum" "an object above its glibc ceiling" \
    env MAX_GLIBC=2.0 "$gate" "$so"
refute "exports libmaxminddb's MMDB_ symbols" "an object exporting MMDB_ symbols" \
    "$gate" "$work/exports.so"
refute "does not export get_module" "an object with get_module hidden" \
    "$gate" "$work/nogetmodule.so"
refute "carries a RUNPATH" "an object with a RUNPATH" "$gate" "$work/runpath.so"
refute "Unexpected runtime dependency" "an object with a non-libc dependency" \
    "$gate" "$work/extradep.so"

# The one case with no ::error:: to match: readelf aborts on a non-object before
# any check runs, which is the correct outcome but not one the gate announces.
if "$gate" /etc/hostname >/dev/null 2>&1; then
    fail "the gate accepted a file that is not an object"
fi

echo "the gate accepted the good object and rejected all eight bad ones."
