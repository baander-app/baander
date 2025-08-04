#!/bin/sh

# Test cleanup script
set -e

echo "Cleaning up test environment..."

# Stop and remove containers including test runner
docker compose -f docker-compose.test.yml --profile test-runner down -v

# Remove test volumes if they exist
docker volume rm baander_postgres_test_data baander_redis_test_data 2>/dev/null || true

# Clean up test artifacts
rm -rf coverage/
rm -rf .phpunit.cache/

echo "Test environment cleaned up!"