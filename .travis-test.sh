#!/bin/sh

set -e
set -x

mkdir -p build/logs
./vendor/bin/phpunit -c .coveralls-phpunit.xml.dist

echo "mbstring.internal_encoding=utf-8" >> ~/.phpenv/versions/"$(phpenv version-name)"/etc/php.ini
echo "mbstring.func_overload = 7" >> ~/.phpenv/versions/"$(phpenv version-name)"/etc/php.ini
./vendor/bin/phpunit

echo "extension = ext/modules/maxminddb.so" >> ~/.phpenv/versions/"$(phpenv version-name)"/etc/php.ini
./vendor/bin/phpunit

if [ $RUN_LINTER ]; then
    vendor/bin/php-cs-fixer fix --verbose --diff --dry-run --config=.php_cs
    vendor/bin/phpcs --standard=.phpcs-ruleset.xml src/
fi
