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

if [[ "$date" -ne  $(date +"%Y-%m-%d") ]]; then
    echo "$date is not today!"
    exit 1
fi

tag="v$version"

rm -fr vendor

perl -pi -e "s/(?<=#define PHP_MAXMINDDB_VERSION \")\d+\.\d+\.\d+(?=\")/$version/" ext/php_maxminddb.h

php composer.phar self-update
php composer.phar update

./vendor/bin/phpunit

echo $'\nDiff:'
git diff

if [ -n "$(git status --porcelain)" ]; then
    git commit -m "Bumped version to $version" -a
fi

echo $'\nRelease notes:'
echo "$notes"


read -p "Push to origin? (y/n) " should_push

if [ "$should_push" != "y" ]; then
    echo "Aborting"
    exit 1
fi

echo "Creating tag $tag"

message="$version

$notes"

git push

hub release create -m "$message" "$tag"
