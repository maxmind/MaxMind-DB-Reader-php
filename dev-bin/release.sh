#!/bin/bash

set -eu -o pipefail

changelog=$(cat CHANGELOG.md)

regex='
([0-9]+\.[0-9]+\.[0-9]+) \(([0-9]{4}-[0-9]{2}-[0-9]{2})\)
-*

((.|
)*)
'

if [[ ! $changelog =~ $regex ]]; then
      echo "Could not find date line in change log!"
      exit 1
fi

version="${BASH_REMATCH[1]}"
date="${BASH_REMATCH[2]}"
notes="$(echo "${BASH_REMATCH[3]}" | sed -n -e '/^[0-9]\+\.[0-9]\+\.[0-9]\+/,$!p')"

if [[ "$date" !=  $(date +"%Y-%m-%d") ]]; then
    echo "$date is not today!"
    exit 1
fi

tag="v$version"

rm -fr vendor

perl -pi -e "s/(?<=#define PHP_MAXMINDDB_VERSION \")\d+\.\d+\.\d+(?=\")/$version/" ext/php_maxminddb.h
perl -pi -e "s/(?<=\"ext-maxminddb\": \"<)\d+.\d+.\d+(?=,)/$version/" composer.json
perl -pi -e "s/(?<=<(?:api)>)\d+\.\d+\.\d+(?=<)/$version/" package.xml
perl -pi -e "s/(?<=<(?:release)>)\d+\.\d+\.\d+(?=<)/$version/" package.xml
perl -0777 -pi -e "s{(?<=<notes>).*(?=</notes>)}{$notes}sm" package.xml
perl -pi -e "s/(?<=<date>)\d{4}-\d{2}-\d{2}(?=<)/$date/" package.xml

pushd ext
phpize
./configure
make
popd

php -n -dextension=ext/modules/maxminddb.so composer.phar self-update
php -n -dextension=ext/modules/maxminddb.so composer.phar update

php -n -dextension=ext/modules/maxminddb.so ./vendor/bin/phpunit
php -n ./vendor/bin/phpunit

echo $'\nDiff:'
git diff

if [ -n "$(git status --porcelain)" ]; then
    git commit -m "Bumped version to $version" -a
fi

echo $'\nRelease notes:'
echo "$notes"

pecl package

read -p "Push to origin? (y/n) " should_push

if [ "$should_push" != "y" ]; then
    echo "Aborting"
    exit 1
fi

echo "Creating tag $tag"

git push

gh release create --target "$(git branch --show-current)" -t "$version" -n "$notes" "$tag"

echo "Upload PECL package to pecl.php.net!"
