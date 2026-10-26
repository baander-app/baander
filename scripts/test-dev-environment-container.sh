#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BAANDER_TEST_IMAGE:?Select an already built CI image for disposable workflow qualification}"
: "${BAANDER_TEST_POSTGRES_IMAGE:?Select the project PostgreSQL image with its extensions}"
python3 -m unittest discover -s scripts/tests -p 'test_dev_environment_*.py'
BAANDER_DATABASE_TEST_IMAGE="$BAANDER_TEST_POSTGRES_IMAGE" bash scripts/test-dev-database-container.sh
bash scripts/test-dev-worker-source-container.sh
printf '%s\n' 'PASS: isolated workflow contracts, database safety and real worker source admission.'
printf '%s\n' 'Host DNS/trust, timer-to-browser updates, simultaneous previews and playback remain separate qualification gates.'
