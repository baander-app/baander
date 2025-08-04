#!/bin/sh

# Get shell access to test container
set -e

echo "Opening shell in test container..."

# Check if test environment is running
if ! docker compose -f docker-compose.test.yml ps app-test | grep -q "Up"; then
    echo "Test environment not running. Starting..."
    ./bin/test-setup.sh
fi

# Open bash shell in the test container
docker compose -f docker-compose.test.yml exec app-test bash