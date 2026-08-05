#!/usr/bin/env bash
#
# Refuse to ship an extension object that is not self-contained.
#
# Shared with maxmind/MaxMind-DB-Reader-php-ext, which reaches this file
# through its submodule checkout and gates the objects it publishes with it.
# One implementation is the only way the bar cannot drift between the two.
#
# Every tool runs in its own assignment and the greps read captured output,
# never a live pipe: `set -e` is suspended inside an `if` condition, so
# `if some-tool "$so" | grep ...` cannot tell "no match" from "not installed".
# For the same reason an unmeasurable result -- an empty NEEDED list, glibc
# floor or minos -- is fatal rather than a pass.
#
# Usage: gate-extension.sh <path to the extension object>
# Reads MAX_GLIBC (Linux) or MACOSX_DEPLOYMENT_TARGET (macOS); the two callers
# set different values, so the limit belongs at the call site.

set -euo pipefail

fail() {
    echo "::error::$*"
    exit 1
}

# Before "$1" is dereferenced: under `set -u` a bare `so="$1"` would abort with
# bash's own message instead of this one.
[ $# -eq 1 ] || fail "Usage: gate-extension.sh <path to the extension object>"

so="$1"

# True when $2 is no higher a version than $1. Both operands are padded to the
# same component count first, so `at_most 11 11.0` is not read as greater.
at_most() {
    local a="$1" b="$2"
    # An empty operand would compare as "no higher" and waive the check.
    [ -n "$a" ] || fail "at_most called with an empty ceiling."
    [ -n "$b" ] || fail "at_most called with an empty measurement."
    while [ "$(awk -F. '{print NF}' <<<"$a")" -lt "$(awk -F. '{print NF}' <<<"$b")" ]; do a="$a.0"; done
    while [ "$(awk -F. '{print NF}' <<<"$b")" -lt "$(awk -F. '{print NF}' <<<"$a")" ]; do b="$b.0"; done
    [ "$(printf '%s\n%s\n' "$a" "$b" | sort -V | tail -n1)" = "$a" ]
}

[ -f "$so" ] || fail "$so does not exist or is not a regular file."

# Checks 3, 3b and 3c. They differ between platforms only in the nm invocation
# and Mach-O's leading underscore, so the caller sets $undefined and $defined
# and passes the prefix. Keeping one copy is not cosmetic: the two have already
# drifted once, when only the Linux greps were anchored.
#
# 3b exists because vendoring makes libmaxminddb's API part of this object's
# exports, and PHP dlopens extensions with RTLD_GLOBAL, so a process that also
# loads a system libmaxminddb could bind across the two. config.m4 passes
# -fvisibility=hidden to prevent it; this asserts that worked. MSVC exports
# nothing unmarked, so config.w32 needs no equivalent.
#
# 3c is its counterweight: -fvisibility=hidden covers our own maxminddb.c too,
# and get_module survives only because ZEND_GET_MODULE expands through
# ZEND_DLEXPORT, which carries visibility("default").
check_symbols() {
    local prefix="$1" exported

    if grep -E "(^|[[:space:]])${prefix}MMDB_" <<<"$undefined"; then
        fail "Undefined MMDB_ symbols remain."
    fi

    exported="$(grep -E "(^|[[:space:]])${prefix}MMDB_" <<<"$defined" || true)"
    if [ -n "$exported" ]; then
        printf '%s\n' "$exported"
        fail "The object exports libmaxminddb's MMDB_ symbols; they should be hidden."
    fi

    if ! grep -qE "(^|[[:space:]])${prefix}get_module$" <<<"$defined"; then
        fail "The object does not export get_module; PHP will reject it as not a PHP library."
    fi
}


# Informational only; file(1) ships separately from binutils.
file "$so" || echo "file(1) is unavailable; skipping the object summary."

case "$(uname -s)" in
Linux)
    dynamic="$(readelf -d "$so")"

    # 1. No libmaxminddb dependency -- the point of the bundled build is that
    #    users need nothing but libc. The parenthesis is optional because GNU
    #    readelf prints `(NEEDED)` and llvm-readelf a bare `NEEDED`.
    needed="$(sed -n 's/.*(\{0,1\}NEEDED)\{0,1\}.*\[\(.*\)\]/\1/p' <<<"$dynamic")"
    printf 'NEEDED:\n%s\n' "$needed"
    # Every shared object needs at least libc, so none means the parse failed.
    if [ -z "$needed" ]; then
        fail "Could not read any DT_NEEDED entries from $so; the gate proved nothing."
    fi
    if grep -qi maxminddb <<<"$needed"; then
        fail "The object still links libmaxminddb."
    fi

    # 2. No RUNPATH/RPATH -- a search path baked in from the build container is
    #    meaningless, or worse, on a user's machine.
    if grep -E '\(?(RUNPATH|RPATH)\)?' <<<"$dynamic"; then
        fail "The object carries a RUNPATH/RPATH."
    fi

    # 3, 3b, 3c -- the symbol checks.
    undefined="$(nm -D -u "$so")"
    defined="$(nm -D --defined-only "$so")"
    check_symbols ''

    # 4a. Nothing but the C runtime. Check 1 rejects libmaxminddb by name; this
    #     makes any other new dependency a deliberate decision. musl spells its
    #     libc libc.musl-<arch>.so.1, and libatomic turns up for 64-bit atomics
    #     on some 32-bit architectures; neither is built here today.
    while read -r lib; do
        [ -n "$lib" ] || continue
        case "$lib" in
        libc.so.* | libc.musl-*.so.* | libm.so.* | libdl.so.* | librt.so.* | \
        libpthread.so.* | ld-linux*.so.* | ld-musl-*.so.* | libgcc_s.so.* | \
        libatomic.so.*) ;;
        *) fail "Unexpected runtime dependency $lib; the object should need nothing but the C runtime." ;;
        esac
    done <<<"$needed"

    # 4. Measured glibc floor must not exceed the documented maximum. The
    #    `|| true` is scoped to the grep, which legitimately exits non-zero on
    #    no match; wrapping the whole pipeline would swallow a sed, sort or
    #    tail failure too, and a partial result yields a floor lower than the
    #    real one, which passes the ceiling check.
    syms="$(objdump -T "$so")"
    # Unstable at any version, so an absolute bar rather than part of the floor.
    if grep -qE 'GLIBC_PRIVATE' <<<"$syms"; then
        fail "The object references GLIBC_PRIVATE, which is not a stable interface."
    fi
    floor="$({ grep -oE 'GLIBC_[0-9]+(\.[0-9]+)+' <<<"$syms" || true; } | sed 's/^GLIBC_//' | sort -uV | tail -n1)"
    if [ -z "$floor" ]; then
        fail "Could not measure a glibc floor for $so; the gate proved nothing."
    fi
    # GLIBC_ABI_DT_RELR carries no version, so the pattern above cannot see it,
    # yet it needs glibc >= 2.36. Raise the floor rather than reject: requiring
    # 2.36 is only wrong against a lower ceiling. Ordered after the empty-floor
    # guard so it cannot paper over a failed measurement.
    if grep -qE 'GLIBC_ABI_DT_RELR' <<<"$syms"; then
        echo "The object requires GLIBC_ABI_DT_RELR, so its floor is at least 2.36."
        floor="$(printf '%s\n2.36\n' "$floor" | sort -uV | tail -n1)"
    fi
    echo "Measured glibc floor: $floor (documented maximum $MAX_GLIBC)"
    if ! at_most "$MAX_GLIBC" "$floor"; then
        fail "Requires glibc $floor, above the documented maximum $MAX_GLIBC."
    fi
    ;;
Darwin)
    # Reached only from the extension repository's macOS lane; the
    # bundled-build workflow here is Linux-only.
    linked="$(otool -L "$so")"
    loadcmds="$(otool -l "$so")"
    undefined="$(nm -u "$so")"

    # 1. No libmaxminddb dependency. tail -n +2 drops otool's echo of the
    #    object's own path.
    printf '%s\n' "$linked"
    if tail -n +2 <<<"$linked" | grep -i maxminddb; then
        fail "The object still links libmaxminddb."
    fi

    # 2. No LC_RPATH: a Homebrew prefix baked in here would not exist on a
    #    user's machine.
    if grep -A3 LC_RPATH <<<"$loadcmds"; then
        fail "The object carries an LC_RPATH."
    fi

    # 3, 3b, 3c -- the symbol checks, with Mach-O's leading underscore.
    # `nm -gU` (external only, defined only) means the same to Apple's nm and
    # to the llvm-nm now behind it.
    defined="$(nm -gU "$so")"
    check_symbols _

    # 4a. Nothing but libSystem, the Mach-O counterpart of the NEEDED
    #     allowlist. Only indented lines whose first field is an absolute path
    #     are dependencies, which skips otool's echo of the object's own path
    #     and, on a universal binary, the architecture headers.
    dylibs="$(awk '/^[[:space:]]+\// {print $1}' <<<"$linked")"
    if [ -z "$dylibs" ]; then
        fail "Could not read any linked dylibs from $so; the gate proved nothing."
    fi
    while read -r lib; do
        [ -n "$lib" ] || continue
        case "$lib" in
        /usr/lib/libSystem.B.dylib | /usr/lib/system/*) ;;
        *) fail "Unexpected runtime dependency $lib; the object should need nothing but libSystem." ;;
        esac
    done <<<"$dylibs"

    # 4. The macOS analogue of the glibc floor. Every slice is measured, not
    #    just the first: otool -l emits load commands per architecture, arm64
    #    has a hard 11.0 floor, and Xcode clamps minos per architecture, so an
    #    x86_64 slice at 11.0 can hide an arm64 slice at 14.0.
    # A slice targeting 10.13 or lower carries LC_VERSION_MIN_MACOSX instead,
    # which the awk below cannot see. On a universal object mixing the two the
    # empty-result guard never fires -- all_minos is non-empty from the modern
    # slices -- and the old-style one is silently unmeasured, so reject it
    # outright rather than measuring around it.
    if grep -q LC_VERSION_MIN_MACOSX <<<"$loadcmds"; then
        fail "$so carries LC_VERSION_MIN_MACOSX; its deployment target cannot be measured here."
    fi
    all_minos="$(awk '/LC_BUILD_VERSION/{f=1} f && $1=="minos"{print $2; f=0}' <<<"$loadcmds")"
    minos="$(sort -V <<<"$all_minos" | tail -n1)"
    printf 'Measured minimum macOS per slice: %s (documented maximum %s)\n' \
        "${all_minos:-none}" "$MACOSX_DEPLOYMENT_TARGET"
    if [ -z "$minos" ]; then
        # A target of 10.13 or lower emits LC_VERSION_MIN_MACOSX instead.
        fail "Could not read an LC_BUILD_VERSION minos from $so; the deployment target is unverified. An object targeting 10.13 or lower carries LC_VERSION_MIN_MACOSX instead."
    fi
    if ! at_most "$MACOSX_DEPLOYMENT_TARGET" "$minos"; then
        fail "Requires macOS $minos, above the documented maximum $MACOSX_DEPLOYMENT_TARGET."
    fi
    ;;
*)
    fail "No gate implemented for $(uname -s)."
    ;;
esac
