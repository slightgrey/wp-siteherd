#!/usr/bin/env sh
# Builds wp-siteherd.zip from the committed HEAD: the asset that the GitHub
# release must carry for sites to update themselves (Siteherd_Self_Update).
set -e
cd "$(dirname "$0")/.."
version=$(sed -n "s/^define( 'SITEHERD_VERSION', '\(.*\)' );/\1/p" wp-siteherd.php)
git archive --format=zip --prefix=wp-siteherd/ -o wp-siteherd.zip HEAD
echo "Built wp-siteherd.zip for version $version."
echo "Publish it as an asset of the GitHub release tagged v$version."
