#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || { echo 'Host PHP needs pdo_pgsql.' >&2; exit 1; }
run_id="baander-recovery-$(date +%s)-$$"
pg_id=''
cleanup() {
    for owned_id in "$pg_id"; do
        if [ -n "$owned_id" ]; then docker rm -f --volumes "$owned_id" >/dev/null 2>&1 || true; fi
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
docker exec -i "$pg_id" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < tests/Fixtures/postgres-extensions.sql
port_mapping=$(docker port "$pg_id" 5432/tcp)
WORKER_RECOVERY_PG_PORT=${port_mapping##*:}
[[ "$port_mapping" == 127.0.0.1:* && "$WORKER_RECOVERY_PG_PORT" =~ ^[0-9]+$ ]] || { echo 'Unexpected PostgreSQL port mapping.' >&2; exit 1; }
export WORKER_RECOVERY_PG_PORT
docker_binary=$(command -v docker)
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
image_id=$(docker image inspect --format '{{.Id}}' "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}")
timeout 120s php tests/Fixtures/Worker/recover-deployment.php "$docker_binary" "$endpoint" "$image_id"
echo 'PASS: real Docker + PostgreSQL paired external-controller recovery'
