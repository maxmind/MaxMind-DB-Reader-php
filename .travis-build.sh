#!/bin/sh

if [ "hhvm" != "$(phpenv version-name)" ]
then
  phpize
  ./configure --with-maxminddb --enable-maxminddb-debug
  make clean
  make
  NO_INTERACTION=1 make test
  cd ..
fi
