#!/usr/bin/env bash
# Mutation testing of the classes a pull request changes or adds, as the whole plugin takes too long for every push.
# Usage: mutate-changed.sh <base ref> [minimum score]
set -euo pipefail

base="${1:?base ref}"
min="${2:-75}"

# src/services/Foo.php => SomehowDigital\Craft\Hub\services\Foo
classes=$(git diff --name-only --diff-filter=AM "origin/${base}...HEAD" -- 'src/*.php' \
  | sed -E 's#^src/(.*)\.php$#SomehowDigital/Craft/Hub/\1#; s#/#\\#g' \
  | paste -sd, -)

if [ -z "$classes" ]; then
  echo 'No changed classes to mutate.'
  exit 0
fi

echo "Mutating: ${classes//,/ }"
exec vendor/bin/pest --mutate --covered-only --parallel --min="$min" --class="$classes"
