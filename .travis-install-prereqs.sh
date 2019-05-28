#!/bin/sh

set -e
set -x

COMPOSER_FLAGS="--dev -n --prefer-source"
if [ "$TRAVIS_PHP_VERSION" = "7.4" ] || [ "$TRAVIS_PHP_VERSION" = "8.0" ] || [ "$TRAVIS_PHP_VERSION" = "master" ]; then
    COMPOSER_FLAGS="$COMPOSER_FLAGS --ignore-platform-reqs"
fi

git submodule update --init --recursive
composer self-update
composer install $COMPOSER_FLAGS
mkdir -p "$HOME/libmaxminddb"
git clone --recursive git://github.com/maxmind/libmaxminddb
cd libmaxminddb
./bootstrap
./configure --prefix="$HOME/libmaxminddb"
make
make install
