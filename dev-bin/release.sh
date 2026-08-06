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

# The extension release is a handoff: this script pushes a tag and the
# extension repository's release.yml does the rest. If that workflow is not on
# its default branch, the tag push triggers nothing -- and the tag guard
# further down then refuses every retry, while `gh workflow run` cannot help
# either, because workflow_dispatch also resolves the workflow from the default
# branch. Checked here, before anything has been published.
if ! gh workflow view release.yml --repo maxmind/MaxMind-DB-Reader-php-ext &>/dev/null; then
    echo "Error: release.yml is not on the extension repository's default branch."
    echo "Pushing a tag there would build nothing, and it cannot be retried."
    echo "Merge maxmind/MaxMind-DB-Reader-php-ext#2 first. Nothing has been published yet."
    exit 1
fi

check_command perl
check_command php
check_command phpize
check_command pecl

# composer.phar is managed by mise (see mise.toml)
check_command composer.phar

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

# The notes become this repository's release body, package.xml's <notes>, and
# the extension tag's annotation, which is the extension release's body. An
# unusual heading layout that made the filter above yield nothing would publish
# all four empty without complaint -- `git tag -a -m ""` exits 0.
if [ -z "${notes//[[:space:]]/}" ]; then
    echo "Error: extracted empty release notes from CHANGELOG.md."
    exit 1
fi

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

# Every substitution below is asserted to match something.
#
# `perl -pi` exits 0 whether or not the pattern matched, and exits 0 on a
# missing file too, printing only to stderr -- so a substitution can quietly do
# nothing and the release proceeds. That is not hypothetical: the
# ext-maxminddb floor in composer.json stopped being updated in April 2024,
# when the constraint's separator changed from a comma to " || " and left the
# old anchor matching nothing, and four releases shipped a stale floor before
# anyone noticed. The suppressor is the `git status --porcelain` test further
# down, which treats "nothing changed" as normal when it is only ever a bug.
#
# Counted in a separate read-only pass because the substituting run cannot
# report it: with -i, perl has already renamed the rewritten file into place by
# the time END could inspect a counter.
subst() { # <file> <s/// expression> [extra perl flags...]
    local file="$1" expr="$2"
    shift 2
    if [ ! -f "$file" ]; then
        echo "Error: $file is missing; the release tooling cannot update it."
        exit 1
    fi
    local matches
    matches="$(perl "$@" -ne "\$n += $expr; END { print \$n + 0 }" "$file")"
    if [ "$matches" -eq 0 ]; then
        echo "Error: nothing in $file matched, so its version would not be updated."
        echo "The file's format has probably changed. Pattern: $expr"
        exit 1
    fi
    perl "$@" -pi -e "$expr" "$file"
}

# Passed through the environment rather than interpolated into perl source by
# the shell. $notes is free text from the changelog, and perl would re-read a
# double-quoted replacement as code: "$reader" and "@args" -- ordinary words in
# a PHP project's release notes -- become variable lookups and vanish.
export RELEASE_VERSION="$version" RELEASE_DATE="$date" RELEASE_NOTES="$notes"

# shellcheck disable=SC2016 # $ENV{...} is perl source; the shell must not expand it
{
    subst README.md 's{(?<=php composer\.phar require maxmind-db/reader:).+}{^$ENV{RELEASE_VERSION}}g'
    subst ext/php_maxminddb.h 's/(?<=#define PHP_MAXMINDDB_VERSION ")\d+\.\d+\.\d+(?=")/$ENV{RELEASE_VERSION}/'
    # Matched by what ends the version rather than by the version's own shape.
    # This line is the one that broke: the constraint was written
    # "<1.11.1,>=2.0.0" until April 2024 and "<1.11.1 || >=2.0.0" after, and an
    # anchor tied to the separator stopped matching. Tying it to the digits
    # instead just moves the problem -- \d+\.\d+\.\d+ does not match
    # "1.14.0-beta1", which the changelog regex explicitly permits. Consuming
    # everything up to a space, comma, pipe or quote handles every shape the
    # file has had, a prerelease, and a `composer normalize` that collapses the
    # spaces.
    subst composer.json 's/(?<="ext-maxminddb": "<)[^ ,|"]+/$ENV{RELEASE_VERSION}/'
    subst package.xml 's/(?<=<(?:api)>)\d+\.\d+\.\d+(?=<)/$ENV{RELEASE_VERSION}/'
    subst package.xml 's/(?<=<(?:release)>)\d+\.\d+\.\d+(?=<)/$ENV{RELEASE_VERSION}/'
    subst package.xml 's{(?<=<notes>).*(?=</notes>)}{$ENV{RELEASE_NOTES}}sm' -0777
    subst package.xml 's/(?<=<date>)\d{4}-\d{2}-\d{2}(?=<)/$ENV{RELEASE_DATE}/'
}

pushd ext
phpize
./configure
make
popd

php -n -dextension=ext/modules/maxminddb.so "$(mise which composer.phar)" update

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
    if ! git clone --recurse-submodules "$ext_repo_url" "$ext_repo_dir"; then
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

# .ext is only cloned when it is absent, so a pre-existing clone made without
# --recurse-submodules leaves this an empty directory. git's repository
# discovery walks *up*, so the fetch and checkout below would then run against
# .ext itself and detach its HEAD at its own same-named tag -- and succeed, so
# set -e never fires and the branch reports contentment.
if [ ! -e MaxMind-DB-Reader-php/.git ]; then
    echo "ERROR: $ext_repo_dir/MaxMind-DB-Reader-php is not a git checkout."
    echo "The clone was probably made without --recurse-submodules. Run:"
    echo "  git -C $ext_repo_dir submodule update --init"
    popd >/dev/null
    exit 1
fi

cd MaxMind-DB-Reader-php
git fetch --tags origin
if ! git checkout "$tag"; then
    echo "ERROR: Failed to checkout tag $tag in submodule"
    popd >/dev/null
    exit 1
fi

cd ..

# Stage submodule update
git add MaxMind-DB-Reader-php

# Check if there are actual changes
if [ -z "$(git status --porcelain)" ]; then
    echo "No commit needed in extension repository (submodule already at $tag)"
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
fi

# Tagging the extension repository is what triggers its release workflow, which
# builds the pre-packaged source tarball and the precompiled binaries, uploads
# them all, and publishes the release. This has to happen even when the submodule
# commit above turned out to be unnecessary: on a re-run, or after someone bumped
# the submodule by hand, that commit is a no-op but the tag has still never been
# pushed, and it is the tag rather than the commit that starts the release.
if ! ext_remote_tag="$(git ls-remote --tags origin "refs/tags/$tag")"; then
    echo "ERROR: Could not list tags in the extension repository remote"
    popd >/dev/null
    exit 1
fi

if [ -n "$ext_remote_tag" ]; then
    echo "ERROR: Tag $tag already exists in the extension repository."
    echo "The release workflow only runs on a newly pushed tag, so re-pushing"
    echo "it would build nothing. Check whether the release is already there:"
    echo "https://github.com/maxmind/MaxMind-DB-Reader-php-ext/releases/tag/$tag"
    echo "To rebuild the assets for a tag that has already been pushed, run the"
    echo "workflow by hand against that tag:"
    echo "gh workflow run release.yml --repo maxmind/MaxMind-DB-Reader-php-ext -f tag=$tag"
    popd >/dev/null
    exit 1
fi

# The tag must be annotated: the workflow creates the release with
# --notes-from-tag, which reads the annotation as the release notes.
# -f replaces a tag left behind by an earlier failed run rather than aborting;
# the check above has already established that it was never pushed.
echo "Tagging $tag in extension repository..."
git tag -f -a "$tag" -m "$notes"

echo "Pushing tag $tag..."
git push origin "refs/tags/$tag"

echo ""
echo "✓ Extension repository tagged $tag"
echo "✓ Its release workflow will build and publish the release assets"

popd >/dev/null

echo ""
echo "==================================================================="
echo "RELEASE COMPLETE"
echo "==================================================================="
echo ""
echo "Main repository: https://github.com/maxmind/MaxMind-DB-Reader-php/releases/tag/$tag"
echo "Extension repository: https://github.com/maxmind/MaxMind-DB-Reader-php-ext/releases/tag/$tag"
echo "  (published by CI once the release workflow finishes)"
echo ""
echo "Action items:"
echo "1. Watch the extension repository's release workflow and confirm that it"
echo "   published the release with all of its assets:"
echo "   https://github.com/maxmind/MaxMind-DB-Reader-php-ext/actions/workflows/release.yml"
echo "   It builds the pre-packaged source tarball and the precompiled binaries,"
echo "   checks them, and only then un-drafts the release. Until it succeeds the"
echo "   release stays a draft, and it smoke tests 'pie install' itself at the end."
echo "2. Upload PECL package to pecl.php.net: https://pecl.php.net/package-new.php"
echo "   File: $package"
echo "3. Announce release"
