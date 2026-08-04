#!/usr/bin/env bash
#
# Assert that gate-extension.sh rejects what it should.
#
# Every other caller runs the gate over a good object, so it is only ever
# observed succeeding. An inverted grep, a dropped `!`, or a pattern written in
# the wrong regex dialect would leave both this repository and
# maxmind/MaxMind-DB-Reader-php-ext green while the gate certified anything --
# not hypothetical, since the ERE/BRE distinction has already bitten that way.
#
# These are the cheap cases: a ceiling below the measured floor, an input that
# is not an object, a path that does not exist, and no argument at all.
#
# Usage: test-gate-extension.sh <path to a known-good extension object>

set -euo pipefail

so="$1"
gate="$(dirname "${BASH_SOURCE[0]}")/gate-extension.sh"

[ -f "$so" ] || { echo "::error::$so does not exist; nothing to test the gate against."; exit 1; }

refute() { # <description> <command...>
    local what="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        echo "::error::the gate accepted $what"
        exit 1
    fi
}

refute "an object above its glibc ceiling" env MAX_GLIBC=2.0 "$gate" "$so"
refute "a file that is not an object" "$gate" /etc/hostname
refute "a path that does not exist" "$gate" /nonexistent/maxminddb.so
refute "a missing argument" "$gate"

echo "the gate rejected all four."
