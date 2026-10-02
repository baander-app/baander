#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || { echo 'Host PHP needs pdo_pgsql.' >&2; exit 1; }
run_id="baander-recovery-$(date +%s)-$$"
pg_id=''
predecessor_id=''
wrong_label_id=''
cleanup() {
    for owned_id in "$predecessor_id" "$wrong_label_id" "$pg_id"; do
        if [ -n "$owned_id" ]; then docker rm -f "$owned_id" >/dev/null 2>&1 || true; fi
    done
}
trap cleanup EXIT
export POSTGRES_USER=worker_recovery POSTGRES_DB=worker_recovery
POSTGRES_PASSWORD=$(php -r 'echo bin2hex(random_bytes(24));')
export POSTGRES_PASSWORD PGCONNECT_TIMEOUT=5
# Credentials travel in environment, never command arguments or logs. This
# disposable PostgreSQL instance has only an ephemeral loopback-published port.
pg_id=$(docker run --detach --name "$run_id-pg" --publish 127.0.0.1::5432 \
    --memory 512m --cpus 1 --pids-limit 64 --env POSTGRES_USER --env POSTGRES_PASSWORD --env POSTGRES_DB \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}")
ready=false
for attempt in $(seq 1 30); do
    if docker exec "$pg_id" pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; then ready=true; break; fi
    sleep 1
done
if [ "$ready" != true ]; then
    docker logs "$pg_id" >&2
    echo 'Disposable PostgreSQL failed to become ready.' >&2
    exit 1
fi
port_mapping=$(docker port "$pg_id" 5432/tcp)
WORKER_RECOVERY_PG_PORT=${port_mapping##*:}
[[ "$port_mapping" == 127.0.0.1:* && "$WORKER_RECOVERY_PG_PORT" =~ ^[0-9]+$ ]] || { echo 'Unexpected PostgreSQL port mapping.' >&2; exit 1; }
export WORKER_RECOVERY_PG_PORT
WORKER_RECOVERY_BOOT_ID=$(php -r 'echo bin2hex(random_bytes(16));')
export WORKER_RECOVERY_BOOT_ID
predecessor_name=$(php -r 'require "vendor/autoload.php"; echo \App\Shared\Infrastructure\Worker\RegisteredDeploymentStart::containerName("baander.app:recovery-test", getenv("WORKER_RECOVERY_BOOT_ID"));')

# Predecessor stays never-started until the registered PHP controller admits it.
# The deliberately mislabeled fault container is started directly. The test
# controller runs on the host; no Docker socket or database credential enters them.
for role in predecessor wrong-label; do
    boot_id=$WORKER_RECOVERY_BOOT_ID
    container_name=$predecessor_name
    restart_policy=no
    if [ "$role" = wrong-label ]; then
        boot_id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
        container_name="$run_id-$role"
        restart_policy=always
    fi
    container_id=$(docker create --name "$container_name" --network none --memory 256m --memory-swap 256m \
        --cpus 1 --pids-limit 32 --cap-drop ALL --security-opt no-new-privileges --cgroupns private --restart "$restart_policy" \
        --label app.baander.worker.namespace=baander.app:recovery-test \
        --label "app.baander.worker.boot-id=$boot_id" --label app.baander.worker.role=deployment \
        --entrypoint php "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" \
        /tmp/tests/Fixtures/Worker/contained-supervisor.php)
    if [ "$role" = predecessor ]; then predecessor_id=$container_id; else wrong_label_id=$container_id; fi
    tar -cf - vendor packages src tests/Fixtures/Worker | docker cp - "$container_id:/tmp/"
    if [ "$role" = predecessor ]; then continue; fi
    docker start "$container_id" >/dev/null
    ready=false
    for attempt in $(seq 1 50); do
        if docker exec "$container_id" test -s /tmp/baander-descendant-ready >/dev/null 2>&1; then ready=true; break; fi
        sleep 0.1
    done
    if [ "$ready" != true ]; then docker logs "$container_id" >&2; echo 'Worker descendant failed to become ready.' >&2; exit 1; fi
done
docker_binary=$(command -v docker)
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
timeout 60s php tests/Fixtures/Worker/recover-deployment.php "$docker_binary" "$endpoint" "$predecessor_id" "$wrong_label_id"
echo 'PASS: real Docker + PostgreSQL external-controller recovery (not LeasedWorkerRuntime end to end)'
