#!/bin/sh

git submodule update --init --recursive
composer self-update
composer install --dev -n --prefer-source
if [ "hhvm" != "$(phpenv version-name)" ]
then
  git clone --recursive git://github.com/maxmind/libmaxminddb
  cd libmaxminddb
  ./bootstrap
  ./configure
  make
  sudo make install
  sudo ldconfig
  cd ../ext
  phpize
  ./configure --with-maxminddb --enable-maxminddb-debug
  make
  NO_INTERACTION=1 make test
  cd ..
fi
pyrus install pear/PHP_CodeSniffer
phpenv rehash
