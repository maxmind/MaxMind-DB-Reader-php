PHP_ARG_ENABLE(maxminddb,
    [Whether to enable the MaxMind DB Reader extension],
    [  --enable-maxminddb      Enable MaxMind DB Reader extension support])

if test $PHP_MAXMINDDB != "no"; then
    PHP_CHECK_LIBRARY(maxminddb, MMDB_open)

    PHP_CHECK_LIBRARY(gmp, __gmp_randinit_lc_2exp_size,
        [],[
        PHP_CHECK_LIBRARY(gmp, gmp_randinit_lc_2exp_size,
        [],[
            AC_MSG_ERROR([GNU MP Library version 4.1.2 or greater required.])
        ],[])
        ],[
    ])

    PHP_ADD_LIBRARY(gmp, $1, MAXMINDDB_SHARED_LIBADD)
    PHP_ADD_LIBRARY(maxminddb, 1, MAXMINDDB_SHARED_LIBADD)
    PHP_SUBST(MAXMINDDB_SHARED_LIBADD)

    PHP_NEW_EXTENSION(maxminddb, maxminddb.c, $ext_shared)
fi
