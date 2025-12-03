#!/bin/bash

set -eu -o pipefail

# Pre-flight checks - verify all required tools are available and configured
# before making any changes to the repository

check_command() {
    if ! command -v "$1" &>/dev/null; then
        echo "Error: $1 is not installed or not in PATH"
        exit 1
    fi
}

# Verify gh CLI is authenticated
if ! gh auth status &>/dev/null; then
    echo "Error: gh CLI is not authenticated. Run 'gh auth login' first."
    exit 1
fi

# Verify we can access this repository via gh
if ! gh repo view --json name &>/dev/null; then
    echo "Error: Cannot access repository via gh. Check your authentication and repository access."
    exit 1
fi

# Verify git can connect to the remote (catches SSH key issues, etc.)
if ! git ls-remote origin &>/dev/null; then
    echo "Error: Cannot connect to git remote. Check your git credentials/SSH keys."
    exit 1
fi

check_command perl
check_command php
check_command phpize
check_command pecl

# Check that we're not on the main branch
current_branch=$(git branch --show-current)
if [ "$current_branch" = "main" ]; then
    echo "Error: Releases should not be done directly on the main branch."
    echo "Please create a release branch and run this script from there."
    exit 1
fi

# Fetch latest changes and check that we're not behind origin/main
echo "Fetching from origin..."
git fetch origin

if ! git merge-base --is-ancestor origin/main HEAD; then
    echo "Error: Current branch is behind origin/main."
    echo "Please merge or rebase with origin/main before releasing."
    exit 1
fi

changelog=$(cat CHANGELOG.md)

regex='
([0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?) \(([0-9]{4}-[0-9]{2}-[0-9]{2})\)
-*

((.|
)*)
'

if [[ ! $changelog =~ $regex ]]; then
    echo "Could not find date line in change log!"
    exit 1
fi

version="${BASH_REMATCH[1]}"
date="${BASH_REMATCH[3]}"
notes="$(echo "${BASH_REMATCH[4]}" | sed -n -E '/^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?/,$!p')"

if [[ "$date" != "$(date +"%Y-%m-%d")" ]]; then
    echo "$date is not today!"
    exit 1
fi

tag="v$version"

if [ -n "$(git status --porcelain)" ]; then
    echo ". is not clean." >&2
    exit 1
fi

rm -fr vendor

perl -pi -e "s{(?<=php composer\.phar require maxmind-db/reader:).+}{^$version}g" README.md
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

package="maxminddb-$version.tgz"

read -r -p "Push to origin? (y/n) " should_push

if [ "$should_push" != "y" ]; then
    echo "Aborting"
    exit 1
fi

echo "Creating tag $tag"

git push

gh release create --target "$(git branch --show-current)" -t "$version" -n "$notes" "$tag"

# =============================================================================
# EXTENSION REPOSITORY RELEASE AUTOMATION
# =============================================================================

ext_repo_dir=".ext"
ext_repo_url="git@github.com:maxmind/MaxMind-DB-Reader-php-ext.git"

echo ""
echo "==================================================================="
echo "UPDATING EXTENSION REPOSITORY"
echo "==================================================================="

# Check if extension repository exists locally
if [ ! -d "$ext_repo_dir" ]; then
    echo "Extension repository not found at: $ext_repo_dir"
    echo "Cloning extension repository..."
    git clone --recurse-submodules "$ext_repo_url" "$ext_repo_dir"

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to clone extension repository"
        echo "Please clone manually: git clone --recurse-submodules $ext_repo_url $ext_repo_dir"
        exit 1
    fi
fi

# Navigate to extension repository
pushd "$ext_repo_dir" >/dev/null

# Safety check: ensure working directory is clean
if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: Extension repository has uncommitted changes"
    echo "Please commit or stash changes in: $ext_repo_dir"
    popd >/dev/null
    exit 1
fi

# Ensure we're on main branch
current_branch=$(git rev-parse --abbrev-ref HEAD)
if [ "$current_branch" != "main" ]; then
    echo "Switching to main branch..."
    git checkout main
fi

# Pull latest changes
echo "Pulling latest changes from origin..."
git pull origin main

# Update submodule to the new tag
echo "Updating submodule to $tag..."
cd MaxMind-DB-Reader-php
git fetch --tags origin
git checkout "$tag"

if [ $? -ne 0 ]; then
    echo "ERROR: Failed to checkout tag $tag in submodule"
    popd >/dev/null
    exit 1
fi

cd ..

# Stage submodule update
git add MaxMind-DB-Reader-php

# Check if there are actual changes
if [ -z "$(git status --porcelain)" ]; then
    echo "No changes needed in extension repository (already at $tag)"
    popd >/dev/null
    echo "Extension repository is up to date"
else
    # Commit submodule update
    echo "Committing submodule update..."
    git commit -m "Update to MaxMind-DB-Reader-php $version

This updates the submodule reference to track the $tag release.

Release notes from main repository:
$notes"

    # Push changes
    echo "Pushing to origin..."
    git push origin main

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to push to extension repository"
        popd >/dev/null
        exit 1
    fi

    # Create pre-packaged source tarball for PIE
    # PIE needs this because it doesn't handle git submodules automatically
    echo "Creating pre-packaged source tarball for PIE..."
    pie_tarball="maxminddb-${tag}.tgz"

    # Create tarball with files at root level (PIE requirement)
    # Note: naming must be {extension-name}-v{version}.tgz
    pushd MaxMind-DB-Reader-php/ext >/dev/null
    tar -czf "../../$pie_tarball" *
    popd >/dev/null

    if [ ! -f "$pie_tarball" ]; then
        echo "ERROR: Failed to create source tarball"
        popd >/dev/null
        exit 1
    fi

    echo "Created $pie_tarball"

    # Create corresponding release in extension repo with same tag
    echo "Creating release $tag in extension repository..."
    gh release create "$tag" \
        --repo maxmind/MaxMind-DB-Reader-php-ext \
        --title "$version" \
        --notes "Extension release for MaxMind-DB-Reader-php $version

This release tracks the $tag tag of the main repository.

## Release notes from main repository

$notes" \
        "$pie_tarball"

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to create release in extension repository"
        echo "You may need to create it manually at:"
        echo "https://github.com/maxmind/MaxMind-DB-Reader-php-ext/releases/new?tag=$tag"
        popd >/dev/null
        exit 1
    fi

    # Clean up tarball
    rm -f "$pie_tarball"

    echo ""
    echo "✓ Extension repository updated successfully!"
    echo "✓ Release created: https://github.com/maxmind/MaxMind-DB-Reader-php-ext/releases/tag/$tag"
    echo "✓ Pre-packaged source uploaded: $pie_tarball"
fi

popd >/dev/null

echo ""
echo "==================================================================="
echo "RELEASE COMPLETE"
echo "==================================================================="
echo ""
echo "Main repository: https://github.com/maxmind/MaxMind-DB-Reader-php/releases/tag/$tag"
echo "Extension repository: https://github.com/maxmind/MaxMind-DB-Reader-php-ext/releases/tag/$tag"
echo ""
echo "Action items:"
echo "1. Upload PECL package to pecl.php.net: https://pecl.php.net/package-new.php"
echo "   File: $package"
echo "2. Verify PIE installation: pie install maxmind-db/reader-ext:^$version"
echo "3. Announce release"
