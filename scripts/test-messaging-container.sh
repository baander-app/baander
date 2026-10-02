#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }
run_id="baander-messaging-$(date +%s)-$$"
cleanup() {
    docker rm -f "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$run_id" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=messaging_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
ready=false
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d messaging_test >/dev/null 2>&1 &&
        docker exec "$run_id-redis" redis-cli ping | grep -qx PONG; then
        ready=true
        break
    fi
    sleep 1
done
# The image's temporary initialization server listens only on a Unix socket.
# Wait for TCP so its shutdown cannot be mistaken for application readiness.
if [ "$ready" != true ]; then
    echo 'Messaging services did not become ready within 30 attempts.' >&2
    docker logs --tail 40 "$run_id-postgres" >&2 || true
    docker logs --tail 40 "$run_id-redis" >&2 || true
    exit 1
fi

tar -cf - vendor src tests config packages migrations bin phpunit.xml.dist \
    .env .env.test composer.json composer.lock |
    docker run --rm --privileged --network "$run_id" -i --entrypoint sh \
        -e MESSENGER_TEST_REDIS_DSN=redis://redis:6379 \
        -e OUTBOX_TEST_DATABASE_URL=postgresql://baander:test-only@postgres:5432/messaging_test \
        "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" -c '
            set -eu
            mkdir -p /tmp/baander-tests
            tar -xf - -C /tmp/baander-tests
            cd /tmp/baander-tests
            exec php vendor/bin/phpunit -c phpunit.xml.dist tests/Integration/MessengerJsonDeliveryTest.php tests/Integration/MessengerRetentionTest.php tests/Integration/MessengerDelayedPromotionTest.php tests/Integration/NotificationRetryDeliveryTest.php tests/Integration/CoverExtractionRetryTest.php tests/Integration/OutboxLeaseTest.php tests/Integration/OutboxNotificationReplayTest.php tests/Integration/AccessTokenCacheTransactionTest.php tests/Integration/PgroongaSearchCompatibilityTest.php tests/Integration/WorkerDeploymentLeaseTest.php tests/Integration/WorkerLeaseAgentTest.php tests/Integration/WorkerDeploymentContainmentTest.php tests/Integration/WorkerDeploymentInventoryTest.php tests/Integration/RegisteredDeploymentRecoveryTest.php tests/Integration/RegisteredDeploymentStartTest.php \
                --no-progress --colors=never --display-all-issues --fail-on-phpunit-notice --fail-on-skipped
        '
