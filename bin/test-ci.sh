#!/bin/sh

# CI/CD test runner
set -e

echo "Running tests in CI mode..."

# Start services with test runner
docker compose -f docker-compose.test.yml --profile test-runner up -d

# Wait for services to be healthy
echo "Waiting for services..."
docker compose -f docker-compose.test.yml --profile test-runner wait

# Generate app key and run migrations inside container
docker compose -f docker-compose.test.yml exec app-test php artisan key:generate --env=testing --force
docker compose -f docker-compose.test.yml exec app-test php artisan migrate:fresh --env=testing --force

# Run tests with coverage inside container
docker compose -f docker-compose.test.yml exec app-test ./vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml

# Cleanup
docker compose -f docker-compose.test.yml --profile test-runner down -v

echo "CI tests completed!"