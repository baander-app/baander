#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
exec php "$root/vendor/bin/phpunit" --configuration "$root/phpunit-tooling.xml.dist" "$root/scripts/tests/php/WorkerCleanupTest.php" "$@"
