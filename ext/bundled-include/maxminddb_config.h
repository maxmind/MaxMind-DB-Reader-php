#ifndef MAXMINDDB_CONFIG_H
#define MAXMINDDB_CONFIG_H

/* Intentionally empty.
 *
 * libmaxminddb's maxminddb.h includes "maxminddb_config.h" unconditionally,
 * and its own build system generates that header. We do not run that build, so
 * this satisfies the include while ext/config.m4 and ext/config.w32 pass the
 * values it would have defined on the command line -- which keeps them
 * authoritative even where a generated header is also present.
 *
 * It is a tracked file rather than something the two build systems write at
 * configure time: generating it meant writing into the submodule on Windows,
 * which left it dirty after every build.
 */

#endif /* MAXMINDDB_CONFIG_H */
