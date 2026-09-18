#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
php tools/generate-administration.php --check
find admin api site plugins -type f -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -r -n1 php -l >/dev/null
composer validate --no-check-publish
composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts
# This path is owned by the builder; no user-configured deletion target is accepted.
rm -rf -- "$root/build/component"
php tools/package.php --stage
composer dump-autoload --working-dir="$root/build/component/admin" --no-dev --no-scripts --classmap-authoritative --no-interaction
php tools/package.php --archive
