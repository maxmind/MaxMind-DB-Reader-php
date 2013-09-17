PHP_ARG_ENABLE(maxminddb,
    [Whether to enable the MaxMind DB Reader extension],
    [  --enable-maxminddb      Enable MaxMind DB Reader extension support])

if test $PHP_MAXMINDDB != "no"; then
    PHP_CHECK_LIBRARY(maxminddb, MMDB_open)

    AC_CHECK_TYPE(
        [unsigned __int128],
        [AC_DEFINE([MISSING_UINT128], [0], [Missing the unsigned __int128 type])],
        [AC_DEFINE([MISSING_UINT128], [1], [Missing the unsigned __int128 type])])

    PHP_ADD_LIBRARY(maxminddb, 1, MAXMINDDB_SHARED_LIBADD)
    PHP_SUBST(MAXMINDDB_SHARED_LIBADD)

    PHP_NEW_EXTENSION(maxminddb, maxminddb.c, $ext_shared)
fi
