#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BAANDER_TEST_IMAGE:?Select an already built CI image for disposable workflow qualification}"
: "${BAANDER_TEST_POSTGRES_IMAGE:?Select the project PostgreSQL image with its extensions}"
case "${1:-}" in
  --components-only) components_only=1 ;;
  "") components_only=0 ;;
  *) printf 'Unknown option: %s\n' "$1" >&2; exit 2 ;;
esac
if [[ "$components_only" == 0 ]]; then
  : "${BAANDER_QUALIFICATION_WORKSPACE:?Select a new disposable qualification workspace outside the checkout}"
  : "${BAANDER_QUALIFICATION_STATE_ROOT:?Select an empty dedicated external qualification state root}"
fi
python3 -m unittest discover -s scripts/tests -p 'test_dev_environment_*.py'
BAANDER_DATABASE_TEST_IMAGE="$BAANDER_TEST_POSTGRES_IMAGE" bash scripts/test-dev-database-container.sh
bash scripts/test-dev-worker-source-container.sh
if [[ "$components_only" == 1 ]]; then
  printf '%s\n' 'PASS: component safety and source admission. Integrated host acceptance was not selected.'
  exit 0
fi
qualification_args=(--workspace "$BAANDER_QUALIFICATION_WORKSPACE" --state-root "$BAANDER_QUALIFICATION_STATE_ROOT")
if [[ -n "${BAANDER_QUALIFICATION_HOST_STATE_ROOT:-}" ]]; then
  qualification_args+=(--host-state-root "$BAANDER_QUALIFICATION_HOST_STATE_ROOT")
fi
if [[ -n "${BAANDER_QUALIFICATION_PREPARE_SHARED_PATHS:-}" ]]; then
  qualification_args+=(--prepare-shared-paths "$BAANDER_QUALIFICATION_PREPARE_SHARED_PATHS")
fi
if [[ -n "${BAANDER_QUALIFICATION_BROWSER_CHECK:-}" ]]; then
  qualification_args+=(--browser-check "$BAANDER_QUALIFICATION_BROWSER_CHECK")
fi
python3 scripts/dev_environment_acceptance.py "${qualification_args[@]}"
printf '%s\n' 'PASS: component contracts and integrated disposable workflow acceptance.'
