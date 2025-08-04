#!/bin/sh

# Test setup script
set -e

echo "Starting test environment..."

# Start all test services including the app-test container
docker compose -f docker-compose.test.yml --profile test-runner up -d

echo "Waiting for services to be ready..."

# Wait for all services to be healthy
echo "Waiting for services to be healthy..."
docker compose -f docker-compose.test.yml --profile test-runner wait

echo "Services are ready!"

# Generate test app key if not exists
if [ ! -f .env.testing ]; then
    echo "Creating .env.testing file..."
    cp .env.testing.example .env.testing
fi

# Generate app key for testing inside the container
echo "Generating application key..."
docker compose -f docker-compose.test.yml exec app-test php artisan key:generate --env=testing

# Run migrations inside the container
echo "Running migrations..."
docker compose -f docker-compose.test.yml exec app-test php artisan migrate:fresh --env=testing --seed

echo "Test environment is ready!"
echo ""
echo "To run tests:"
echo "  ./bin/test-run.sh"
echo ""
echo "To run tests with coverage:"
echo "  ./bin/test-run.sh --coverage"
echo ""
echo "To run quick tests:"
echo "  ./bin/test-quick.sh"
echo ""
echo "To stop test environment:"
echo "  docker compose -f docker-compose.test.yml --profile test-runner down"
