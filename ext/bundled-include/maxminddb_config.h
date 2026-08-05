#ifndef MAXMINDDB_CONFIG_H
#define MAXMINDDB_CONFIG_H

/* Supplies the header libmaxminddb's maxminddb.h includes unconditionally and
 * that libmaxminddb's own build system would generate. We never run that
 * build.
 *
 * This is the canonical account of the arrangement; ext/config.m4 and
 * ext/config.w32 point here rather than repeating it.
 *
 * It is a tracked file rather than something the build systems write at
 * configure time: generating it meant writing inside the submodule on Windows,
 * which left it dirty after every build. Nothing includes anything from the
 * submodule root, so that directory is deliberately off the include path.
 *
 * PACKAGE_VERSION lives here rather than on the command line. It has to reach
 * libmaxminddb's sources, which is what MMDB_lib_version() returns, but our own
 * ext/maxminddb.c also includes php.h -- and PHP only began stripping PACKAGE_*
 * from its generated headers in 7.4, so a -D on the global CFLAGS redefines
 * PHP's macro on 7.2 and 7.3. That is a warning today and a build failure under
 * --enable-maxminddb-debug, which adds -Werror.
 *
 * The #ifndef makes this a no-op wherever PACKAGE_VERSION is already defined:
 * ext/maxminddb.c includes php.h before maxminddb.h, so it keeps PHP's value
 * and never sees a redefinition -- harmless, because it reads MMDB_lib_version()
 * rather than the macro. config.w32 passes its own /D, read out of the
 * submodule's configure.ac, which likewise wins over this default.
 *
 * Keep the version in step with the submodule. test-bundled.yml's "Load the
 * extension on its own" step compares MMDB_LIB_VERSION against configure.ac on
 * every build, so a stale value here fails CI rather than shipping.
 */
#ifndef PACKAGE_VERSION
#define PACKAGE_VERSION "1.13.3"
#endif

#endif /* MAXMINDDB_CONFIG_H */
