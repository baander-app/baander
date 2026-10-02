#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }
run_id="baander-retirement-$(date +%s)-$$"
container_id=''
cleanup() {
    if [ -n "$container_id" ]; then docker rm -f "$container_id" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT
boot_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
# Every supervisor/helper/worker lives in this one private PID namespace. The
# Docker socket is available only to the host-side test/controller.
container_id=$(docker create --name "$run_id" --network none --memory 256m --memory-swap 256m \
    --cpus 1 --pids-limit 32 --cap-drop ALL --security-opt no-new-privileges \
    --cgroupns private --restart always \
    --label app.baander.worker.namespace=baander.app:retirement-test \
    --label "app.baander.worker.boot-id=$boot_id" --label app.baander.worker.role=deployment \
    --entrypoint php "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" \
    /tmp/tests/Fixtures/Worker/contained-supervisor.php)
tar -cf - vendor packages src tests/Fixtures/Worker | docker cp - "$container_id:/tmp/"
docker start "$container_id" >/dev/null
ready=false
for attempt in $(seq 1 50); do
    if docker exec "$container_id" test -s /tmp/baander-descendant-ready >/dev/null 2>&1; then ready=true; break; fi
    sleep 0.1
done
if [ "$ready" != true ]; then
    docker logs "$container_id" >&2
    echo 'Worker descendant failed to become ready.' >&2
    exit 1
fi
docker_binary=$(command -v docker)
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
# Wrong owner must leave the live predecessor intact.
if php tests/Fixtures/Worker/retire-container.php "$docker_binary" "$endpoint" "$container_id" bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb > /tmp/"$run_id"-rejected.log 2>&1; then
    echo 'Wrong owner unexpectedly retired deployment.' >&2
    exit 1
fi
test "$(docker inspect --format '{{.State.Running}}' "$container_id")" = true
php tests/Fixtures/Worker/retire-container.php "$docker_binary" "$endpoint" "$container_id" "$boot_id"
# Successful immutable-ID removal, rather than stopped-state inspection, prevents
# this restart-always predecessor from being restarted after database release.
if docker start "$container_id" >/dev/null 2>&1; then
    echo 'Retired predecessor could be restarted.' >&2
    exit 1
fi
if php tests/Fixtures/Worker/retire-container.php "$docker_binary" "$endpoint" "$container_id" "$boot_id" > /tmp/"$run_id"-absent.log 2>&1; then
    echo 'Absent predecessor incorrectly treated as a retirement receipt.' >&2
    exit 1
fi
echo 'PASS: wrong-owner rejection and running deployment retirement with restart prevention'
