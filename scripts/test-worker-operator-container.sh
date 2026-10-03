#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE=1 timeout 240s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }
php_binary="${BAANDER_TEST_PHP_BINARY:-php}"
"$php_binary" -r 'foreach (["posix", "pdo_pgsql"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Host operator requires PHP extension: " . $extension . "\n"); exit(1); } }'
run_id="baander-worker-operator-$(date +%s)-$$"
fixture_image="$run_id:fixture"
fixture_base="$run_id:base"
work="$(mktemp -d /tmp/baander-worker-operator.XXXXXXXX)"
chmod 700 "$work"
worker_name=''
docker_binary="$(command -v docker)"
docker_endpoint="${BAANDER_TEST_DOCKER_ENDPOINT:-unix:///var/run/docker.sock}"
# The fixture services and the controller must use the same explicit local daemon.
docker_local() { "$docker_binary" --host "$docker_endpoint" "$@"; }
cleanup() {
    if [ -n "$worker_name" ]; then docker_local rm -f "$worker_name" >/dev/null 2>&1 || true; fi
    docker_local rm -f "$run_id-prepare" "$run_id-source" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker_local image rm -f "$fixture_image" >/dev/null 2>&1 || true
    docker_local image rm "$fixture_base" >/dev/null 2>&1 || true
    docker_local network rm "$run_id" >/dev/null 2>&1 || true
    docker_local network rm "$run_id-control" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
app_image="${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}"
base_id="$(docker_local image inspect --format '{{.Id}}' "$app_image")"
# Dockerfile FROM resolves image references, unlike container create's image IDs.
# Give the already inspected local image an isolated reference without pulling.
docker_local image tag "$base_id" "$fixture_base"
test "$(docker_local image inspect --format '{{.Id}}' "$fixture_base")" = "$base_id"
# Docker recipe isolation forbids both supplied mounts and inherited image VOLUMEs.
test "$(docker_local image inspect --format '{{json .Config.Volumes}}' "$base_id")" = null || {
    echo 'The local fixture base must not declare volumes.' >&2; exit 1;
}
mkdir "$work/checkout"
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    docker_local create --name "$run_id-source" --entrypoint /usr/local/bin/php "$base_id" -r 'exit(0);' >/dev/null
    docker_local cp "$run_id-source:/var/www/html/." - |
        tar --exclude=config/secrets --exclude=./config/secrets -xf - -C "$work/checkout"
    docker_local rm "$run_id-source" >/dev/null
else
    tar --exclude=config/secrets -cf - vendor src tests config packages migrations bin docker/general templates public \
        .env .env.test composer.json composer.lock translations | tar -xf - -C "$work/checkout"
fi
cat > "$work/Dockerfile" <<'DOCKERFILE'
ARG FIXTURE_BASE=scratch
FROM ${FIXTURE_BASE}
USER root
COPY --chown=www-data:www-data checkout/ /var/www/html/
RUN rm -rf /var/www/html/var/cache && mkdir -p /var/www/html/var/cache /var/www/html/var/log && chown -R www-data:www-data /var/www/html/var
RUN rm -rf /var/www/html/config/secrets && mkdir -p /var/www/html/config/secrets/oauth \
    && openssl genrsa -out /var/www/html/config/secrets/oauth/private.key 2048 2>/dev/null \
    && openssl rsa -in /var/www/html/config/secrets/oauth/private.key -pubout -out /var/www/html/config/secrets/oauth/public.key 2>/dev/null \
    && chown -R www-data:www-data /var/www/html/config/secrets
ENV APP_ENV=prod APP_DEBUG=0 XDEBUG_MODE=off
WORKDIR /var/www/html
USER www-data
HEALTHCHECK NONE
ENTRYPOINT ["/usr/local/bin/php"]
CMD []
DOCKERFILE
docker_local build --pull=false --build-arg "FIXTURE_BASE=$fixture_base" --tag "$fixture_image" "$work" > "$work/build.log" 2>&1 || {
    tail -40 "$work/build.log" >&2; exit 1;
}
image_id="$(docker_local image inspect --format '{{.Id}}' "$fixture_image")"
docker_local network create --internal "$run_id" >/dev/null
# Docker 29 suppresses published ports on internal bridges. Only the disposable
# database joins this controller bridge; the worker keeps its sole private network.
docker_local network create "$run_id-control" >/dev/null
docker_local run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker_local run -d --name "$run_id-postgres" --network "$run_id-control" \
    --publish 127.0.0.1::5432 -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=worker_operator_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
docker_local network connect --alias postgres "$run_id" "$run_id-postgres"
ready=false
for attempt in $(seq 1 30); do
    if docker_local exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d worker_operator_test >/dev/null 2>&1 &&
        docker_local exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG; then
        ready=true; break
    fi
    sleep 1
done
if [ "$ready" != true ]; then echo 'Disposable operator services did not become ready.' >&2; exit 1; fi
pg_port="$(docker_local port "$run_id-postgres" 5432/tcp)"
pg_port="${pg_port##*:}"
daemon_id="$(docker_local info --format '{{.ID}}')"
boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
namespace=baander.app:commandtest
worker_name="$(python3 - "$namespace" "$boot" <<'PY'
import hashlib, sys
print('baander-worker-' + hashlib.sha256(sys.argv[1].encode()).hexdigest()[:32] + '-' + sys.argv[2])
PY
)"
python3 - "$work" "$namespace" "$boot" "$daemon_id" "$image_id" "$run_id" "$docker_binary" "$docker_endpoint" "$pg_port" <<'PY'
import json, os, sys
work, namespace, boot, daemon, image, network, binary, endpoint, port = sys.argv[1:]
manifest = dict(version=1, namespace=namespace, bootId=boot, daemonId=daemon, imageId=image,
    network=network, dockerBinary=binary, dockerEndpoint=endpoint, memoryMiB=1664, managementMiB=256,
    consumerMiB=384, relayMiB=384, schedulerMiB=320, scheduledConsoleMiB=320, nanoCpus=1000000000, pidsLimit=64)
runtime = dict(APP_ENV='prod', APP_DEBUG='0', APP_SECRET='operator-test-only',
    DATABASE_URL='postgresql://baander:test-only@postgres:5432/worker_operator_test?serverVersion=18&charset=utf8',
    REDIS_URL='redis://default:test-only@redis:6379', REDIS_PASSWORD='test-only',
    MESSENGER_TRANSPORT_DSN='redis://default:test-only@redis:6379/messages')
credentials = dict(controllerDatabaseUrl=f'postgresql://baander:test-only@127.0.0.1:{port}/worker_operator_test?serverVersion=18&charset=utf8', runtimeEnvironment=runtime)
for name, content in [('manifest', manifest), ('credentials', credentials)]:
    fd = os.open(work + '/' + name + '.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as stream: json.dump(content, stream)
with open(work + '/runtime.env', 'w') as stream:
    for key, value in runtime.items(): stream.write(key + '=' + value + '\n')
os.chmod(work + '/runtime.env', 0o600)
PY
# Preparation containers are disposable service clients; no checkout/socket mounts.
docker_local run --rm --name "$run_id-prepare" --network "$run_id" --env-file "$work/runtime.env" \
    --entrypoint /bin/sh "$image_id" -c '
        set -eu
        php -d memory_limit=512M bin/console cache:clear --env=prod --no-debug
        php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
        php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-outbox "$1" "$2"
        php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-scheduler "$1" "$2"
    ' sh "$namespace" "$boot" > "$work/prepare.log" 2>&1 || {
    tail -40 "$work/prepare.log" >&2; exit 1;
}
operator() {
    local action="$1" expected="$2" code=0
    "$php_binary" bin/worker-deployment.php "$action" "$work/manifest.json" "$work/credentials.json" > "$work/$action.json" 2> "$work/$action.stderr" || code=$?
    if [ "$code" != "$expected" ]; then
        cat "$work/$action.json" >&2
        cat "$work/$action.stderr" >&2
        echo "Operator $action expected exit $expected, got $code." >&2
        exit 1
    fi
    python3 - "$work/$action.json" "$action" "$expected" <<'PY'
import json, sys
with open(sys.argv[1]) as stream: frame = json.load(stream)
assert frame['action'] == sys.argv[2], frame
assert frame['success'] is (sys.argv[3] == '0'), frame
PY
}
operator create 0
test "$(docker_local inspect --format '{{.State.Status}} {{.State.Pid}}' "$worker_name")" = 'created 0'
operator reconcile-create 0
test "$(docker_local inspect --format '{{.State.Status}} {{.State.Pid}}' "$worker_name")" = 'created 0'
operator status 0
python3 - "$work/status.json" "$namespace" "$boot" <<'PY'
import json, sys
with open(sys.argv[1]) as stream: result = json.load(stream)['result']
assert result['creationIntentMatches'] is True, result
assert result['registered'] is True and result['startClaimed'] is False, result
assert result['retirement'] == 'none' and result['reservation'] == 'none', result
assert result['readiness'] == 'not_checked', result
assert result['namespace'] == sys.argv[2] and result['bootId'] == sys.argv[3], result
PY
operator start 0
operator start 3
await_check() {
    local mode="$1" passed=false
    for attempt in $(seq 1 50); do
        if docker_local exec "$worker_name" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
            "$mode" "$namespace" "$boot" > "$work/$mode.log" 2>&1; then
            passed=true; break
        fi
        if [ "$(docker_local inspect --format '{{.State.Running}}' "$worker_name")" != true ]; then break; fi
        sleep 0.2
    done
    if [ "$passed" != true ]; then
        tail -20 "$work/$mode.log" >&2
        docker_local logs --tail 60 "$worker_name" >&2
        echo "Actual application worker check $mode failed." >&2; exit 1
    fi
}
await_check ready
await_check verify-outbox
await_check verify-scheduler
operator status 0
python3 - "$work/status.json" <<'PY'
import json, sys
with open(sys.argv[1]) as stream: result = json.load(stream)['result']
assert result['creationIntentMatches'] is True and result['registered'] is True, result
assert result['startClaimed'] is True and result['retirement'] == 'none', result
assert result['reservation'] == 'this_boot' and result['readiness'] == 'not_checked', result
assert isinstance(result['containerId'], str) and len(result['containerId']) == 64, result
PY
operator recover 0
operator recover 0
operator status 0
python3 - "$work/status.json" <<'PY'
import json, sys
with open(sys.argv[1]) as stream: result = json.load(stream)['result']
assert result['creationIntentMatches'] is True and result['registered'] is True, result
assert result['startClaimed'] is True and result['retirement'] == 'completed', result
assert result['reservation'] == 'none' and result['readiness'] == 'not_checked', result
PY
# The old boot cannot acquire a second container or a new start after retirement.
operator create 3
operator start 3
test -z "$(docker_local container ls --all --no-trunc --filter "name=^/$worker_name$" --format '{{.ID}}')"
echo 'PASS: host operator created and reconciled one stopped immutable fixture, started real application roles, processed outbox/scheduler effects, and retired the exact deployment without same-boot reuse.'
