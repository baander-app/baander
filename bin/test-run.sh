#!/bin/sh

# Test runner script
set -e

# Default values
COVERAGE=false
PARALLEL=false
FILTER=""
TESTSUITE=""

# Parse arguments
while [ $# -gt 0 ]; do
  case $1 in
    --coverage)
      COVERAGE=true
      shift
      ;;
    --parallel)
      PARALLEL=true
      shift
      ;;
    --filter)
      FILTER="$2"
      shift 2
      ;;
    --testsuite)
      TESTSUITE="$2"
      shift 2
      ;;
    *)
      echo "Unknown option $1"
      exit 1
      ;;
  esac
done

# Check if test environment is running
if ! docker compose -f docker-compose.test.yml ps app-test | grep -q "Up"; then
    echo "Test environment not running. Starting..."
    ./bin/test-setup.sh
fi

# Build phpunit command
PHPUNIT_CMD="./vendor/bin/phpunit"

if [ "$COVERAGE" = true ]; then
    PHPUNIT_CMD="$PHPUNIT_CMD --coverage-html coverage/"
fi

if [ "$PARALLEL" = true ]; then
    PHPUNIT_CMD="$PHPUNIT_CMD --processes=4"
fi

if [ -n "$FILTER" ]; then
    PHPUNIT_CMD="$PHPUNIT_CMD --filter=$FILTER"
fi

if [ -n "$TESTSUITE" ]; then
    PHPUNIT_CMD="$PHPUNIT_CMD --testsuite=$TESTSUITE"
fi

echo "Running tests in container..."
echo "Command: $PHPUNIT_CMD"

# Run tests inside the container
docker compose -f docker-compose.test.yml exec app-test $PHPUNIT_CMD

echo "Tests completed!"
