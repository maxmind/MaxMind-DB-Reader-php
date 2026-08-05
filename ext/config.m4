PHP_ARG_WITH(maxminddb,
    [Whether to enable the MaxMind DB Reader extension],
    [  --with-maxminddb      Enable MaxMind DB Reader extension support])

PHP_ARG_WITH(maxminddb-bundled,
    [Whether to build the bundled libmaxminddb sources into the extension],
    [  --with-maxminddb-bundled  Build the bundled libmaxminddb sources into the
                          extension instead of linking a system library], no, no)

PHP_ARG_ENABLE(maxminddb-debug, for MaxMind DB debug support,
    [ --enable-maxminddb-debug    Enable MaxMind DB debug support], no, no)

dnl --with-maxminddb-bundled on its own is otherwise a silent no-op: the whole
dnl block below is skipped, configure exits 0, and make builds nothing.
if test "$PHP_MAXMINDDB_BUNDLED" != "no" && test "$PHP_MAXMINDDB" = "no"; then
    AC_MSG_ERROR([--with-maxminddb-bundled requires --with-maxminddb])
fi

if test $PHP_MAXMINDDB != "no"; then

    maxminddb_sources="maxminddb.c"

    if test "$PHP_MAXMINDDB_BUNDLED" != "no"; then
        dnl The arguments are [if-big], [if-little], [if-unknown], [if-universal],
        dnl and only the first two define the macro, so it is left undefined
        dnl unless the answer is actually known.
        dnl
        dnl Guessing here is not safe. The macro is consumed only by the
        dnl byte-swaps in get_ieee754_float() and get_ieee754_double(), so a
        dnl wrong value builds, links and loads perfectly well and then returns
        dnl garbage for every float and double -- latitude, longitude,
        dnl accuracyRadius -- while strings and integers stay correct.
        dnl
        dnl Under a universal (multi -arch) build no single configure-time value
        dnl can be right for every slice. In both cases libmaxminddb's own
        dnl maxminddb.h derives it from __BYTE_ORDER__ behind
        dnl `#if !defined(MMDB_LITTLE_ENDIAN)`, which is per-architecture correct
        dnl and is what we want to fall through to.
        dnl
        dnl That fall-through relies on __BYTE_ORDER__, which every compiler
        dnl able to build PHP defines, and maxminddb.h covers Windows
        dnl separately. Where it is genuinely absent the macro stays undefined,
        dnl `#if MMDB_LITTLE_ENDIAN` evaluates it as 0, and the build assumes
        dnl big-endian silently -- correct on a big-endian target and wrong on a
        dnl little-endian one. Upstream aborts instead, having no universal
        dnl build to accommodate. Compiling with -Wundef surfaces exactly this
        dnl case; it is not added here because PHP's own headers do not build
        dnl warning-free under it, and --enable-maxminddb-debug turns warnings
        dnl into errors.
        dnl
        dnl The third action must be non-empty: autoconf treats an empty
        dnl argument as absent, and its default for action-if-unknown is to
        dnl abort rather than fall through. The fourth is passed for symmetry;
        dnl its default is a harmless AC_DEFINE libmaxminddb never reads.
        m4_define([_mmdb_endian_unknown],
                  [AC_MSG_NOTICE([endianness undetermined; letting maxminddb.h derive MMDB_LITTLE_ENDIAN from __BYTE_ORDER__])])
        AC_C_BIGENDIAN([CFLAGS="$CFLAGS -DMMDB_LITTLE_ENDIAN=0"],
                       [CFLAGS="$CFLAGS -DMMDB_LITTLE_ENDIAN=1"],
                       [_mmdb_endian_unknown], [_mmdb_endian_unknown])

        dnl -fvisibility=hidden keeps libmaxminddb's MMDB_* API from becoming
        dnl part of this object's export table. Vendoring turns those from
        dnl someone else's exports into ours, and PHP dlopens extensions with
        dnl RTLD_GLOBAL on common builds, so a process that also loads
        dnl something linked against a system libmaxminddb could bind across
        dnl the two. dev-bin/gate-extension.sh asserts the result.
        dnl
        dnl -UHAVE_CONFIG_H must precede -DHAVE_CONFIG_H=0: PHP's CPPFLAGS define
        dnl HAVE_CONFIG_H, and redefining it is a warning that becomes an error
        dnl under --enable-maxminddb-debug, which adds -Werror.
        dnl
        dnl PACKAGE_VERSION is deliberately absent: it is defined in
        dnl ext/bundled-include/maxminddb_config.h instead, because a -D here
        dnl also reaches ext/maxminddb.c, which includes php.h -- and PHP only
        dnl began stripping PACKAGE_* from its generated headers in 7.4, so on
        dnl 7.2 and 7.3 this redefined PHP's own macro. See that header.
        CFLAGS="$CFLAGS -fvisibility=hidden -UHAVE_CONFIG_H -DHAVE_CONFIG_H=0 -DMMDB_UINT128_USING_MODE=0 -DMMDB_UINT128_IS_BYTE_ARRAY=1"

        maxminddb_sources="$maxminddb_sources libmaxminddb/src/maxminddb.c libmaxminddb/src/data-pool.c"
    else
        AC_PATH_PROG(PKG_CONFIG, pkg-config, no)

        AC_MSG_CHECKING(for libmaxminddb)
        if test -x "$PKG_CONFIG" && $PKG_CONFIG --exists libmaxminddb; then
            dnl retrieve build options from pkg-config
            if $PKG_CONFIG libmaxminddb --atleast-version 1.0.0; then
                LIBMAXMINDDB_INC=`$PKG_CONFIG libmaxminddb --cflags`
                LIBMAXMINDDB_LIB=`$PKG_CONFIG libmaxminddb --libs`
                LIBMAXMINDDB_VER=`$PKG_CONFIG libmaxminddb --modversion`
                AC_MSG_RESULT(found version $LIBMAXMINDDB_VER)
            else
                AC_MSG_ERROR(system libmaxminddb must be upgraded to version >= 1.0.0)
            fi
            PHP_EVAL_LIBLINE($LIBMAXMINDDB_LIB, MAXMINDDB_SHARED_LIBADD)
            PHP_EVAL_INCLINE($LIBMAXMINDDB_INC)
        else
            AC_MSG_RESULT(pkg-config information missing)
            AC_MSG_WARN(will use libmaxmxinddb from compiler default path)

            PHP_CHECK_LIBRARY(maxminddb, MMDB_open)
            PHP_ADD_LIBRARY(maxminddb, 1, MAXMINDDB_SHARED_LIBADD)
        fi
    fi

    if test $PHP_MAXMINDDB_DEBUG != "no"; then
        CFLAGS="$CFLAGS -Wall -Wextra -Wno-unused-parameter -Wno-missing-field-initializers -Werror"
    fi

    PHP_SUBST(MAXMINDDB_SHARED_LIBADD)

    PHP_NEW_EXTENSION(maxminddb, $maxminddb_sources, $ext_shared)

    dnl These have to come after PHP_NEW_EXTENSION, which is what defines
    dnl $ext_srcdir and $ext_builddir. Without the build directory, the object
    dnl directory for the bundled sources is never created and they fail to
    dnl compile.
    if test "$PHP_MAXMINDDB_BUNDLED" != "no"; then
        dnl A clone without --recursive otherwise produces a stray "No such
        dnl file or directory", a *successful* configure -- autoconf-generated
        dnl configure does not run under set -e -- and then an opaque failure
        dnl much later at maxminddb.h.
        if test ! -f "$ext_srcdir/libmaxminddb/src/maxminddb.c"; then
            AC_MSG_ERROR([--with-maxminddb-bundled needs the bundled libmaxminddb sources; run "git submodule update --init"])
        fi

        dnl ext/bundled-include supplies maxminddb_config.h, which
        dnl libmaxminddb's maxminddb.h includes unconditionally and its own
        dnl build system would generate. Nothing is included from the submodule
        dnl root, so that directory is deliberately not on the path.
        PHP_ADD_BUILD_DIR([$ext_builddir/libmaxminddb/src])
        PHP_ADD_INCLUDE([$ext_srcdir/bundled-include])
        PHP_ADD_INCLUDE([$ext_srcdir/libmaxminddb/include])
        PHP_ADD_INCLUDE([$ext_srcdir/libmaxminddb/src])
    fi
fi
