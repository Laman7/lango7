#!/bin/sh
# Build the release copy in dist/lango7 and ../lango7-v<version>.zip, leaving out
# everything listed in .distignore (tests, build tooling, macOS metadata).
set -eu
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' lango7.php)
rm -rf dist
mkdir -p dist/lango7
excludes=$(grep -v '^#' .distignore | grep -v '^$' | sed 's/^/--exclude=/')
# shellcheck disable=SC2086
rsync -a $excludes ./ dist/lango7/
(cd dist && rm -f "../../lango7-v$version.zip" && zip -qr "../../lango7-v$version.zip" lango7)
echo "dist/lango7 and ../lango7-v$version.zip ($version)"
