#!/bin/sh
# Installs Craft and Hub in a new project and runs smoke.php against the database given by the CRAFT_DB_* variables.
# Usage: tests/Integration/run.sh /path/to/empty/directory
set -eu

plugin=$(cd "$(dirname "$0")/../.." && pwd)
project=$1

composer create-project craftcms/craft "$project" --no-interaction --no-scripts --quiet
cd "$project"
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.hub path "$plugin"
composer require somehow-digital/craft-hub:@dev --no-interaction --quiet --with-all-dependencies

: > .env
CRAFT_SECURITY_KEY=smoke-test-key php craft install/craft --interactive=0 --username=admin --password=password123 --email=admin@example.test --site-name=Smoke --site-url=https://example.test --language=en-US
CRAFT_SECURITY_KEY=smoke-test-key php craft plugin/install hub --interactive=0
CRAFT_SECURITY_KEY=smoke-test-key php "$plugin/tests/Integration/smoke.php" "$project"
