#!/bin/sh

set -e
set -x

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
fi

if [ "hhvm" != $TRAVIS_PHP_VERSION ] && [ "nightly" != $TRAVIS_PHP_VERSION ]
then
  pyrus install pear/PHP_CodeSniffer
fi
