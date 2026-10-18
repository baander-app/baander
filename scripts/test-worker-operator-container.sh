#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    timeout_seconds=240
    if [ -n "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-}${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}${BAANDER_TEST_WORKER_LIFECYCLE_PHASE:-}" ]; then timeout_seconds=600; fi
    exec env BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE=1 timeout "${timeout_seconds}s" bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; then
    test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }
fi
operator_in_container="${BAANDER_TEST_OPERATOR_IN_CONTAINER:-0}"
[[ "$operator_in_container" = 0 || "$operator_in_container" = 1 ]] || {
    echo 'BAANDER_TEST_OPERATOR_IN_CONTAINER must be 0 or 1.' >&2; exit 1;
}
use_image="${BAANDER_TEST_USE_IMAGE:-0}"
[[ "$use_image" = 0 || "$use_image" = 1 ]] || {
    echo 'BAANDER_TEST_USE_IMAGE must be 0 or 1.' >&2; exit 1;
}
if [ "$use_image" = 1 ] && { [ "$operator_in_container" != 1 ] || [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; }; then
    echo 'BAANDER_TEST_USE_IMAGE requires container operator mode and checkout in the image.' >&2; exit 1;
fi
php_binary="${BAANDER_TEST_PHP_BINARY:-php}"
if [ "$operator_in_container" = 0 ]; then
    test -f vendor/autoload.php || { echo 'Host operator requires Composer dependencies.' >&2; exit 1; }
    "$php_binary" -r 'foreach (["posix", "pdo_pgsql"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Host operator requires PHP extension: " . $extension . "\n"); exit(1); } }'
fi
run_id="baander-worker-operator-$(date +%s)-$$"
fixture_image="$run_id:fixture"
fixture_base="$run_id:base"
controller_image="$run_id:controller"
controller_cli="$run_id:cli"
work="$(mktemp -d /tmp/baander-worker-operator.XXXXXXXX)"
chmod 700 "$work"
worker_name=''
control_name=''
web_name="$run_id-web"
web_port=''
two_command="${BAANDER_TEST_TWO_COMMAND:-0}"
lifecycle_phase="${BAANDER_TEST_WORKER_LIFECYCLE_PHASE:-}"
case "$lifecycle_phase" in ''|redis|postgres) ;; *) echo 'Invalid worker lifecycle phase.' >&2; exit 2 ;; esac
if [ -n "$lifecycle_phase" ] && { [ "$two_command" != 0 ] || [ -n "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-}${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}" ]; }; then
    echo 'Worker lifecycle qualification requires standalone operator mode.' >&2; exit 2
fi
artifacts="${BAANDER_TEST_ARTIFACTS:-$(pwd)/var/test-runs/$run_id}"
if [ "$two_command" = 1 ] || [ -n "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-}${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}$lifecycle_phase" ]; then mkdir -p "$artifacts"; chmod 700 "$artifacts"; fi
source_volume="$run_id-source-media"
storage_volume="$run_id-storage"
state_volume="$run_id-media-state"
secrets_volume="$run_id-web-secrets"
ipc_volume="$run_id-worker-ipc"
docker_binary="$(command -v docker)"
docker_endpoint="${BAANDER_TEST_DOCKER_ENDPOINT:-unix:///var/run/docker.sock}"
# A CI runner can reach a remote daemon through its ambient Docker configuration.
# The trusted controller receives that daemon's socket; the worker receives none.
docker_local() {
    if [ "$operator_in_container" = 1 ] && [ -z "${BAANDER_TEST_DOCKER_ENDPOINT:-}" ]; then
        "$docker_binary" "$@"
    else
        "$docker_binary" --host "$docker_endpoint" "$@"
    fi
}
cleanup() {
    local status=$? cleanup_status=0
    local -a cleanup_docker_arguments=("$docker_binary")
    if [ "$operator_in_container" != 1 ] || [ -n "${BAANDER_TEST_DOCKER_ENDPOINT:-}" ]; then
        cleanup_docker_arguments+=(--host "$docker_endpoint")
    fi
    cleanup_docker() {
        timeout 5s "${cleanup_docker_arguments[@]}" "$@"
    }
    cleanup_inventory() {
        case "$1" in
            container) cleanup_docker container ls --all --no-trunc --format '{{.ID}}|{{.Names}}|{{.Label "app.baander.worker.namespace"}}|{{.Label "app.baander.worker.role"}}' ;;
            network) cleanup_docker network ls --format '{{.Name}}' ;;
            volume) cleanup_docker volume ls --format '{{.Name}}' ;;
            image) cleanup_docker image ls --format '{{.Repository}}:{{.Tag}}' ;;
        esac
    }
    if [ -n "${lifecycle_fault_container:-}" ]; then
        cleanup_docker unpause "$lifecycle_fault_container" >/dev/null 2>&1 || cleanup_status=1
    fi
    if [ "$two_command" = 1 ] || [ -n "${lifecycle_phase:-}" ]; then
        cleanup_docker logs "$run_id-postgres" > "$artifacts/postgres.log" 2>&1 || true
        cleanup_docker inspect --format '{{json .State}}' "$run_id-postgres" > "$artifacts/postgres-state.json" 2>&1 || true
        cleanup_docker logs "$web_name" > "$artifacts/web.log" 2>&1 || true
        if [ -n "$worker_name" ]; then cleanup_docker logs "$worker_name" > "$artifacts/worker.log" 2>&1 || true; fi
        if [ -n "$control_name" ]; then cleanup_docker logs "$control_name" > "$artifacts/control.log" 2>&1 || true; fi
    fi
    if [ "$two_command" = 1 ] && [ -n "${BAANDER_TEST_TWO_COMMAND_CHECKPOINT:-}" ]; then
        timeout 30s "$BAANDER_TEST_TWO_COMMAND_CHECKPOINT" cleanup "$artifacts" "${web_base:-}" || cleanup_status=1
    fi
    # Stop the operator before any worker it could create or control.
    local -a container_names=("$run_id-controller" "$web_name" "$run_id-media-prepare" "$run_id-host-probe" "$run_id-client"
        "$run_id-prepare" "$run_id-source" "$run_id-redis" "$run_id-postgres")
    if [ -n "$worker_name" ]; then container_names+=("$worker_name"); fi
    if [ -n "$control_name" ]; then container_names+=("$control_name"); fi
    local -a network_names=("$run_id" "$run_id-control")
    local -a volume_names=("$source_volume" "$storage_volume" "$state_volume" "$secrets_volume" "$ipc_volume")
    local -a image_names=("$controller_image" "$controller_cli" "$fixture_image" "$fixture_base")
    local kind listing name owner role resource_id inventory_ok
    local -A container_ids=()
    for kind in container network volume image; do
        local -n names="${kind}_names"
        local -A present=() removed=()
        inventory_ok=false
        if listing="$(cleanup_inventory "$kind")"; then
            inventory_ok=true
            while IFS='|' read -r name owner role resource_id; do
                if [ -z "$name" ]; then continue; fi
                if [ "$kind" = container ]; then
                    container_ids["$owner"]="$name"
                    name="$owner"; owner="$role"; role="$resource_id"
                fi
                if [ -z "$name" ]; then continue; fi
                present["$name"]=1
                # A replacement boot may have changed worker_name; its exact
                # namespace and role still identify this drill's other worker.
                if [ "$kind" = container ] && [ -n "${namespace:-}" ] && [ "$owner" = "$namespace" ] && [[ "$role" = control || "$role" = execution ]]; then
                    names+=("$name")
                fi
            done <<< "$listing"
        else
            echo "Cleanup could not inventory $kind resources." >&2
            cleanup_status=1
        fi
        for name in "${names[@]}"; do
            if [ -n "${removed[$name]:-}" ]; then continue; fi
            removed["$name"]=1
            if [ "$inventory_ok" = true ] && [ -z "${present[$name]:-}" ]; then continue; fi
            case "$kind" in
                container)
                    if [ -z "${container_ids[$name]:-}" ]; then cleanup_status=1; continue; fi
                    cleanup_docker container rm -f "${container_ids[$name]}" >/dev/null 2>&1 || cleanup_status=1 ;;
                image) cleanup_docker image rm -f "$name" >/dev/null 2>&1 || cleanup_status=1 ;;
                network|volume) cleanup_docker "$kind" rm "$name" >/dev/null 2>&1 || cleanup_status=1 ;;
            esac
        done
    done
    # Absence is proved by successful listings, never by a failed inspect.
    for kind in container network volume image; do
        local -n names="${kind}_names"
        local -A owned=()
        for name in "${names[@]}"; do owned["$name"]=1; done
        if listing="$(cleanup_inventory "$kind")"; then
            while IFS='|' read -r name owner role resource_id; do
                if [ -z "$name" ]; then continue; fi
                if [ "$kind" = container ]; then
                    container_ids["$owner"]="$name"
                    name="$owner"; owner="$role"; role="$resource_id"
                fi
                if [ -z "$name" ]; then continue; fi
                if [ -n "${owned[$name]:-}" ] || { [ "$kind" = container ] && [ -n "${namespace:-}" ] && [ "$owner" = "$namespace" ] && [[ "$role" = control || "$role" = execution ]]; }; then
                    echo "Cleanup left owned $kind resource: $name" >&2
                    cleanup_status=1
                fi
            done <<< "$listing"
        else
            echo "Cleanup could not verify $kind absence." >&2
            cleanup_status=1
        fi
    done
    timeout 5s rm -rf "$work" || cleanup_status=1
    local final_status=$status
    if [ "$final_status" = 0 ] && [ "$cleanup_status" != 0 ]; then final_status=1; fi
    if [ "$two_command" = 1 ] || [ -n "${lifecycle_phase:-}" ]; then
        if ! python3 - "$artifacts/cleanup.json" "$final_status" "$run_id" "$status" "$cleanup_status" <<'PYRECEIPT'
import json, sys
with open(sys.argv[1], 'w') as stream:
    json.dump(dict(exitStatus=int(sys.argv[2]), project=sys.argv[3], originalExitStatus=int(sys.argv[4]),
        cleanupExitStatus=int(sys.argv[5]), cleanupVerified=sys.argv[5] == '0'), stream)
PYRECEIPT
        then
            echo 'Cleanup could not write its verification receipt.' >&2
            if [ "$final_status" = 0 ]; then final_status=1; fi
        fi
        echo "Combined drill artifacts: $artifacts"
    fi
    exit "$final_status"
}
trap cleanup EXIT
app_image="${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}"
base_id="$(docker_local image inspect --format '{{.Id}}' "$app_image")"
docker_local info --format '{{json .}}' > "$work/host.json"
python3 - "$work/host.json" "$two_command" <<'PYHOST'
import json, sys
host = json.load(open(sys.argv[1]))
minimum_gib = 15 if sys.argv[2] == '1' else 13
if int(__import__('os').environ.get('BAANDER_TEST_AUTOSCALING_MAXIMUM', '1')) == 4:
    minimum_gib += 1
assert host['NCPU'] >= 4 and host['MemTotal'] >= minimum_gib * 1024**3, f'Worker qualification requires four CPUs and {minimum_gib} GiB including dependency/web ceilings and headroom'
assert host['CgroupVersion'] == '2', 'Worker qualification requires cgroup v2'
PYHOST
available_cpus="$(docker_local run --rm --name "$run_id-host-probe" --network none --entrypoint /usr/local/bin/php "$base_id" -r '
$status = file_get_contents("/proc/self/status");
if ($status === false || preg_match("/^Cpus_allowed_list:\s+(.+)$/m", $status, $match) !== 1) { exit(1); }
$cpus = [];
foreach (explode(",", trim($match[1])) as $range) {
    $limits = array_map(intval(...), explode("-", $range));
    foreach (range($limits[0], $limits[1] ?? $limits[0]) as $cpu) { $cpus[] = $cpu; }
}
if (count($cpus) < 4) { exit(1); }
echo json_encode(array_slice($cpus, 0, 4), JSON_THROW_ON_ERROR);
')"
# Dockerfile FROM resolves image references, unlike container create's image IDs.
# Give the already inspected local image an isolated reference without pulling.
docker_local image tag "$base_id" "$fixture_base"
test "$(docker_local image inspect --format '{{.Id}}' "$fixture_base")" = "$base_id"
# Docker recipe isolation forbids both supplied mounts and inherited image VOLUMEs.
test "$(docker_local image inspect --format '{{json (index .Config "Volumes")}}' "$base_id")" = null || {
    echo 'The local fixture base must not declare volumes.' >&2; exit 1;
}
image_id="$base_id"
controller_base="$fixture_base"
# Direct-image acceptance exercises the supplied artifact without copying source,
# clearing its cache, generating keys, or changing its dependency installation.
if [ "$use_image" = 0 ]; then
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
if [ -n "${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}" ]; then
    # Register synthetic workload services only in this disposable fixture image.
    cat > "$work/checkout/config/services_prod.yaml" <<'YAML'
services:
    App\Tests\Fixtures\Worker\Autoscaling\WorkloadCodec:
        autowire: false
        autoconfigure: false
        tags: ['baander.message_payload_codec']
    App\Tests\Fixtures\Worker\Autoscaling\WorkloadHandler:
        autowire: false
        autoconfigure: false
        tags: [{name: messenger.message_handler}]
YAML
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
controller_base="$fixture_image"
fi
if [ "$operator_in_container" = 1 ]; then
    # Docker's official CLI releases use static binaries. Check both that property
    # and execution in the application image before granting access to the socket.
    cli_source="${BAANDER_TEST_DOCKER_CLI_IMAGE:-docker:29.7.2-cli}"
    if ! docker_local image inspect "$cli_source" >/dev/null 2>&1; then
        docker_local pull "$cli_source" > "$work/cli-pull.log" 2>&1 || {
            tail -20 "$work/cli-pull.log" >&2; exit 1;
        }
    fi
    cli_id="$(docker_local image inspect --format '{{.Id}}' "$cli_source")"
    docker_local image tag "$cli_id" "$controller_cli"
    mkdir "$work/controller"
    cat > "$work/controller/Dockerfile" <<'DOCKERFILE'
ARG CONTROLLER_BASE=scratch
ARG DOCKER_CLI=scratch
FROM ${DOCKER_CLI} AS dockercli
FROM ${CONTROLLER_BASE}
USER root
COPY --from=dockercli /usr/local/bin/docker /usr/local/bin/docker
RUN file /usr/local/bin/docker | grep -Eq 'statically linked|static-pie linked' \
    && /usr/local/bin/docker --version \
    && php -r 'foreach (["posix", "pdo_pgsql"] as $extension) { if (!extension_loaded($extension)) { exit(1); } }'
DOCKERFILE
    docker_local build --pull=false --build-arg "CONTROLLER_BASE=$controller_base" --build-arg "DOCKER_CLI=$controller_cli" \
        --tag "$controller_image" "$work/controller" > "$work/controller-build.log" 2>&1 || {
        tail -40 "$work/controller-build.log" >&2; exit 1;
    }
    controller_id="$(docker_local image inspect --format '{{.Id}}' "$controller_image")"
fi
for volume in "$source_volume" "$storage_volume" "$state_volume" "$secrets_volume" "$ipc_volume"; do
    docker_local volume create --label "app.baander.test.project=$run_id" "$volume" >/dev/null
done
media_mounts=(--mount "type=volume,source=$source_volume,target=/media/source,readonly,volume-nocopy"
    --mount "type=volume,source=$storage_volume,target=/var/www/html/storage,volume-nocopy"
    --mount "type=volume,source=$state_volume,target=/var/www/html/var/transcode_state,volume-nocopy")
docker_local network create --internal "$run_id" >/dev/null
# Docker 29 suppresses published ports on internal bridges. Only the disposable
# database joins this controller bridge; the worker keeps its sole private network.
docker_local network create "$run_id-control" >/dev/null
dependency_limits=()
if [ -n "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-}${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}" ]; then
    dependency_cpu="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])[3])' "$available_cpus")"
    dependency_limits=(--cpuset-cpus "$dependency_cpu" --cpus 0.5 --pids-limit 64)
fi
docker_local run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    "${dependency_limits[@]}" --memory 256m --memory-swap 256m \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
pg_publish=()
if [ "$operator_in_container" = 0 ]; then pg_publish=(--publish 127.0.0.1::5432); fi
docker_local run -d --name "$run_id-postgres" --network "$run_id-control" --network-alias postgres \
    "${dependency_limits[@]}" --memory 4g --memory-swap 4g \
    "${pg_publish[@]}" -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=worker_operator_test \
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
if [ "$operator_in_container" = 1 ]; then
    controller_db_host=postgres
    pg_port=5432
    manifest_docker_binary=/usr/local/bin/docker
    manifest_docker_endpoint=unix:///var/run/docker.sock
else
    controller_db_host=127.0.0.1
    pg_port="$(docker_local port "$run_id-postgres" 5432/tcp)"
    pg_port="${pg_port##*:}"
    manifest_docker_binary="$docker_binary"
    manifest_docker_endpoint="$docker_endpoint"
fi
daemon_id="$(docker_local info --format '{{.ID}}')"
boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
namespace="baander.app:$run_id"
worker_name="$(python3 - "$namespace" "$boot" <<'PY'
import hashlib, sys
print('baander-worker-' + hashlib.sha256(sys.argv[1].encode()).hexdigest()[:32] + '-' + sys.argv[2] + '-execution')
PY
)"
control_name="${worker_name%-execution}-control"
python3 - "$work" "$namespace" "$boot" "$daemon_id" "$image_id" "$run_id" "$manifest_docker_binary" "$manifest_docker_endpoint" "$pg_port" "$controller_db_host" "$available_cpus" <<'PY'
import json, os, sys
work, namespace, boot, daemon, image, network, binary, endpoint, port, host, available = sys.argv[1:]
cpus = json.loads(available)
profile = json.load(open('tests/Fixtures/Worker/autoscaling-profile.json'))
maximum = int(os.environ.get('BAANDER_TEST_AUTOSCALING_MAXIMUM', profile['maximumConcurrency']))
assert maximum in (1, 2, 4)
profile['maximumConcurrency'] = maximum
manifest = dict(version=4, namespace=namespace, bootId=boot, daemonId=daemon, imageId=image,
    network=network, dockerBinary=binary, dockerEndpoint=endpoint,
    controlMemoryMiB=2944, executionMemoryMiB=4480+320*(maximum-1), controlNanoCpus=1000000000, executionNanoCpus=2000000000,
    controlPidsLimit=129, executionPidsLimit=256+maximum-1, controlCpuSet=cpus[:1], executionCpuSet=cpus[1:3],
    autoscaling=profile,
    scheduledConsoleMiB=320, ipcVolume=network+"-worker-ipc",
    mediaMounts=[dict(volume=network+'-source-media', target='/media/source', readOnly=True, role='source'),
        dict(volume=network+'-storage', target='/var/www/html/storage', readOnly=False, role='derived'),
        dict(volume=network+'-media-state', target='/var/www/html/var/transcode_state', readOnly=False, role='derived')])
runtime = dict(APP_ENV='prod', APP_DEBUG='0', APP_SECRET='operator-test-only',
    DATABASE_URL='postgresql://baander:test-only@postgres:5432/worker_operator_test?serverVersion=18&charset=utf8',
    REDIS_URL='redis://default:test-only@redis:6379', REDIS_PASSWORD='test-only',
    MESSENGER_TRANSPORT_DSN='redis://default:test-only@redis:6379/messages',
    CONVERT_STORAGE_PATH='/var/www/html/storage/conversions', MEDIA_STORAGE_PATH='/media/source')
credentials = dict(controllerDatabaseUrl=f'postgresql://baander:test-only@{host}:{port}/worker_operator_test?serverVersion=18&charset=utf8', runtimeEnvironment=runtime)
for name, content in [('manifest', manifest), ('credentials', credentials)]:
    fd = os.open(work + '/' + name + '.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as stream: json.dump(content, stream)
with open(work + '/runtime.env', 'w') as stream:
    for key, value in runtime.items(): stream.write(key + '=' + value + '\n')
os.chmod(work + '/runtime.env', 0o600)
PY
# Scratch mounts contain no development media, database or Redis volume.
docker_local run --rm --name "$run_id-media-prepare" --user root --network none \
    --mount "type=volume,source=$source_volume,target=/media/source,volume-nocopy" \
    --mount "type=volume,source=$storage_volume,target=/var/www/html/storage,volume-nocopy" \
    --mount "type=volume,source=$state_volume,target=/var/www/html/var/transcode_state,volume-nocopy" \
    --mount "type=volume,source=$secrets_volume,target=/scratch-secrets,volume-nocopy" \
    --mount "type=volume,source=$ipc_volume,target=/run/baander-worker,volume-nocopy" \
    --entrypoint /bin/sh "$image_id" -c '
        set -eu
        mkdir -p /var/www/html/storage/media /var/www/html/storage/conversions /scratch-secrets/oauth
        openssl genrsa -out /scratch-secrets/oauth/private.key 2048 2>/dev/null
        openssl rsa -in /scratch-secrets/oauth/private.key -pubout -out /scratch-secrets/oauth/public.key 2>/dev/null
        chown -R www-data:www-data /media/source /var/www/html/storage /var/www/html/var/transcode_state /scratch-secrets /run/baander-worker
        chmod 700 /scratch-secrets /scratch-secrets/oauth
        chmod 600 /scratch-secrets/oauth/private.key /scratch-secrets/oauth/public.key
    '
# Preparation containers are disposable service clients; no checkout/socket mounts.
docker_local run --rm --name "$run_id-prepare" --network "$run_id" --env-file "$work/runtime.env" \
    --mount "type=volume,source=$source_volume,target=/media/source,volume-nocopy" \
    --mount "type=volume,source=$storage_volume,target=/var/www/html/storage,volume-nocopy" \
    --mount "type=volume,source=$state_volume,target=/var/www/html/var/transcode_state,volume-nocopy" \
    --mount "type=volume,source=$secrets_volume,target=/var/www/html/config/secrets,readonly,volume-nocopy" \
    -e BAANDER_TEST_TWO_COMMAND="$two_command" \
    --entrypoint /bin/sh "$image_id" -c '
        set -eu
        php -d memory_limit=512M bin/console cache:clear --env=prod --no-debug
        php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
        php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-outbox "$1" "$2"
        php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-scheduler "$1" "$2"
        if [ "$BAANDER_TEST_TWO_COMMAND" = 1 ]; then
            printf "%s\n" "Two-command-drill-only-123!" | php bin/console app:user:create --no-interaction --password --role super-admin --force two-command-admin@baander.app "Two command admin"
            php bin/console app:auth:setup-clients --no-interaction
            php bin/console app:settings:set transcode.enabled true --no-interaction
            php tests/Fixtures/TwoCommand/deployment.php prepare
        fi
    ' sh "$namespace" "$boot" > "$work/prepare.log" 2>&1 || {
    mkdir -p "$artifacts"; chmod 700 "$artifacts"
    cp "$work/prepare.log" "$artifacts/prepare.log"
    head -25 "$work/prepare.log" >&2
    tail -15 "$work/prepare.log" >&2; exit 1;
}
if [ "$operator_in_container" = 1 ]; then
    # This trusted controller needs the host socket's existing SELinux label.
    # Do not relabel the daemon socket or apply this exception to the worker.
    docker_local run -d --name "$run_id-controller" --network "$run_id-control" --user 0 \
        --cap-drop ALL --security-opt no-new-privileges --security-opt label=disable \
        --mount "type=bind,source=${BAANDER_TEST_DOCKER_SOCKET_PATH:-/var/run/docker.sock},target=/var/run/docker.sock" \
        --entrypoint /usr/local/bin/php "$controller_id" -r 'while (true) { usleep(100000); }' >/dev/null
    # Verify the mounted socket before the first durable creation intent.
    test "$(docker_local exec "$run_id-controller" /usr/local/bin/docker --host unix:///var/run/docker.sock info --format '{{.ID}}')" = "$daemon_id" || {
        echo 'Controller socket reaches a different Docker daemon.' >&2; exit 1;
    }
    docker_local exec "$run_id-controller" mkdir -m 700 /tmp/operator
    # docker cp can preserve the host UID. Create files as the controller user
    # instead, without granting ownership-changing capabilities or exposing data.
    for config_file in manifest credentials; do
        docker_local exec -i "$run_id-controller" /bin/sh -c 'umask 077; cat > "$1"' sh \
            "/tmp/operator/$config_file.json" < "$work/$config_file.json"
    done
fi
operator() {
    local action="$1" expected="$2" code=0
    if [ "$operator_in_container" = 1 ]; then
        docker_local exec "$run_id-controller" /usr/local/bin/php /var/www/html/bin/worker-deployment.php \
            "$action" /tmp/operator/manifest.json /tmp/operator/credentials.json > "$work/$action.json" 2> "$work/$action.stderr" || code=$?
    else
        "$php_binary" bin/worker-deployment.php "$action" "$work/manifest.json" "$work/credentials.json" > "$work/$action.json" 2> "$work/$action.stderr" || code=$?
    fi
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
combined_phase() {
    local phase="$1"
    docker_local run --rm --name "$run_id-client" --network "container:$web_name" \
        --cap-drop ALL --security-opt no-new-privileges --env-file "$work/runtime.env" \
        "${media_mounts[@]}" --mount "type=volume,source=$secrets_volume,target=/var/www/html/config/secrets,readonly,volume-nocopy" \
        --entrypoint /usr/local/bin/php "$image_id" tests/Fixtures/TwoCommand/deployment.php "$phase" \
        > "$artifacts/$phase.stdout.log" 2> "$artifacts/$phase.stderr" || {
        cat "$artifacts/$phase.stderr" >&2
        docker_local exec "$web_name" cat /var/www/html/var/log/swoole_prod.log > "$artifacts/swoole.log" 2>&1 || true
        return 1
    }
    docker_local cp "$web_name:/var/www/html/storage/two-command/result-$phase.json" "$artifacts/$phase.json" || return 1
    python3 - "$artifacts/$phase.json" "$phase" <<'PYPHASE'
import json, sys
with open(sys.argv[1]) as stream:
    receipt = json.load(stream)
assert receipt['phase'] == sys.argv[2], 'Phase receipt does not match requested phase'
assert isinstance(receipt['seconds'], (int, float)) and receipt['seconds'] >= 0, 'Invalid phase timing'
assert isinstance(receipt['evidence'], dict), 'Invalid phase evidence'
PYPHASE
}
snapshot() {
    local role="$1" name="$2" label="$3"
    docker_local exec "$name" php tests/Fixtures/TwoCommand/processes.php "$role" > "$artifacts/$label-processes.json"
}
host_identities() {
    local mode="$1"
    docker_local run --rm -i --name "$run_id-host-probe" --pid host --network none \
        --cap-drop ALL --security-opt no-new-privileges --entrypoint /usr/local/bin/php \
        "$image_id" tests/Fixtures/TwoCommand/identities.php "$mode"
}
checkpoint() {
    local phase="$1"
    if [ -n "${BAANDER_TEST_TWO_COMMAND_CHECKPOINT:-}" ]; then
        "$BAANDER_TEST_TWO_COMMAND_CHECKPOINT" "$phase" "$artifacts" "$web_base"
    fi
}
start_web() {
    local -a web_cpu_limits=()
    if [ -n "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-}${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}" ]; then web_cpu_limits=(--cpuset-cpus "$dependency_cpu" --cpus 0.5); fi
    # Relative signed URLs work both inside the web namespace and through its published port.
    docker_local run -d --name "$web_name" --network "$run_id-control" --publish "127.0.0.1:${web_port}:9501" \
        --cap-drop ALL --security-opt no-new-privileges --pids-limit 128 --memory 2g --memory-swap 2g "${web_cpu_limits[@]}" \
        --env-file "$work/runtime.env" -e STREAM_URL_HMAC_SECRET=two-command-drill-only -e OTEL_ENABLED=false \
        -e APP_URL=https://baander.app -e DEFAULT_URI=https://baander.app -e APP_DOMAIN= \
        "${media_mounts[@]}" --mount "type=volume,source=$secrets_volume,target=/var/www/html/config/secrets,readonly,volume-nocopy" \
        --entrypoint /usr/local/bin/php "$image_id" bin/console app:serve --no-interaction >/dev/null
    docker_local network connect "$run_id" "$web_name"
    local ready=false
    for attempt in $(seq 1 60); do
        if docker_local exec "$web_name" curl -fsS -o /dev/null http://127.0.0.1:9501/live 2>/dev/null; then ready=true; break; fi
        sleep .5
    done
    test "$ready" = true || { docker_local logs --tail 50 "$web_name" >&2; return 1; }
    web_base="http://$(docker_local port "$web_name" 9501/tcp)"
    web_port="${web_base##*:}"
}
if [ "$two_command" = 1 ]; then
    start_web
    docker_local exec "$web_name" cat /var/www/html/storage/two-command/state.json > "$artifacts/catalog.json"
    python3 - "$artifacts/browser.json" "$artifacts/catalog.json" <<'PYLOGIN'
import json, os, sys
fd = os.open(sys.argv[1], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, 'w') as stream:
    catalog = json.load(open(sys.argv[2]))
    json.dump(dict(email='two-command-admin@baander.app', password='Two-command-drill-only-123!', moviePublicId=catalog['movie'], videoPublicId=catalog['video'], expectedSeekPosition=30), stream)
PYLOGIN
    checkpoint authenticated
    if [ -z "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-}${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}" ]; then
        combined_phase absent
        snapshot web "$web_name" web-without-worker
    fi
fi
operator create 0
test "$(docker_local inspect --format '{{.State.Status}} {{.State.Pid}}' "$worker_name")" = 'created 0'
test "$(docker_local inspect --format '{{.State.Status}} {{.State.Pid}}' "$control_name")" = 'created 0'
operator reconcile-create 0
test "$(docker_local inspect --format '{{.State.Status}} {{.State.Pid}}' "$worker_name")" = 'created 0'
test "$(docker_local inspect --format '{{.State.Status}} {{.State.Pid}}' "$control_name")" = 'created 0'
operator status 0
python3 - "$work/status.json" "$namespace" "$boot" <<'PY'
import json, sys
with open(sys.argv[1]) as stream: result = json.load(stream)['result']
assert result['manifestMatches'] is True, result
assert set(result['units']) == {'control', 'execution'}, result
assert all(u['containerId'] and not u['startClaimed'] for u in result['units'].values()), result
assert result['retirement'] == 'none' and result['reservation'] == 'none', result
assert result['readiness'] == 'not_checked', result
assert result['autoscaling'] == {'state': 'unavailable'}, result
assert result['namespace'] == sys.argv[2] and result['bootId'] == sys.argv[3], result
PY
# Changing an authorized source volume cannot reuse an existing creation intent.
cp "$work/manifest.json" "$work/manifest-original.json"
python3 - "$work/manifest.json" <<'PYDRIFT'
import json, sys
path = sys.argv[1]
manifest = json.load(open(path))
manifest['mediaMounts'][0]['volume'] += '-drift'
with open(path, 'w') as stream: json.dump(manifest, stream)
PYDRIFT
if [ "$operator_in_container" = 1 ]; then
    docker_local exec -i "$run_id-controller" /bin/sh -c 'umask 077; cat > /tmp/operator/manifest.json' < "$work/manifest.json"
fi
operator status 0
python3 - "$work/status.json" <<'PYDRIFTSTATUS'
import json, sys
assert json.load(open(sys.argv[1]))['result']['manifestMatches'] is False
PYDRIFTSTATUS
operator start 3
test "$(docker_local inspect --format '{{.State.Status}} {{.State.Pid}}' "$worker_name")" = 'created 0'
mv "$work/manifest-original.json" "$work/manifest.json"
if [ "$operator_in_container" = 1 ]; then
    docker_local exec -i "$run_id-controller" /bin/sh -c 'umask 077; cat > /tmp/operator/manifest.json' < "$work/manifest.json"
fi
operator start 0
operator start 3
await_check() {
    local mode="$1" passed=false
    local check_container="$worker_name"
    if [[ "$mode" = ready* ]]; then check_container="${2:-$control_name}"; fi
    for attempt in $(seq 1 50); do
        if docker_local exec "$check_container" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
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
await_check ready "$worker_name"
await_check verify-outbox
await_check verify-scheduler
operator status 0
python3 - "$work/status.json" "$work/manifest.json" <<'PYAUTOSCALING'
import json, sys
status = json.load(open(sys.argv[1]))['result']['autoscaling']
maximum = json.load(open(sys.argv[2]))['autoscaling']['maximumConcurrency']
assert status['state'] == 'known' and status['authorityValid'] and status['controllerFresh'], status
assert 0 <= status['desired'] <= maximum and status['occupied'] <= maximum, status
assert status['resourceFresh'] and status['resources']['state'] == 'known', status
assert status['evidence']['queueState'] == 'known', status
PYAUTOSCALING
if [ -n "$lifecycle_phase" ]; then
    source tests/Fixtures/Worker/lifecycle-checkpoint.sh
    lifecycle_checkpoint
    exit 0
fi
if [ -n "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-}${BAANDER_TEST_AUTOSCALING_CHECKPOINT:-}" ]; then
    "${BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT:-$BAANDER_TEST_AUTOSCALING_CHECKPOINT}" "$artifacts" "$control_name" "$worker_name" "$ipc_volume" "$run_id-postgres" worker_operator_test
    operator recover 0
    operator recover 0
    operator status 0
    python3 - "$work/status.json" <<'PYCAPACITYRETIRE'
import json, sys
result = json.load(open(sys.argv[1]))['result']
assert result['manifestMatches'] is True and result['retirement'] == 'completed', result
assert result['reservation'] == 'none', result
assert set(result['units']) == {'control', 'execution'}, result
assert all(unit['removed'] for unit in result['units'].values()), result
PYCAPACITYRETIRE
    cp "$work/status.json" "$artifacts/capacity-retirement.json"
    echo 'PASS: capacity checkpoint completed and both immutable worker units retired.'
    exit 0
fi
operator status 0
python3 - "$work/status.json" <<'PY'
import json, sys
with open(sys.argv[1]) as stream: result = json.load(stream)['result']
assert result['manifestMatches'] is True, result
assert all(u['startClaimed'] for u in result['units'].values()) and result['retirement'] == 'none', result
assert result['reservation'] == 'this_boot' and result['readiness'] == 'not_checked', result
assert all(isinstance(u['containerId'], str) and len(u['containerId']) == 64 for u in result['units'].values()), result
PY
if [ "$two_command" = 1 ]; then
    combined_phase attach
    snapshot web "$web_name" web-attached
    snapshot worker "$worker_name" worker-attached
    python3 - "$artifacts/worker-attached-processes.json" <<'PYOWNER'
import json, sys
snapshot = json.load(open(sys.argv[1]))
video = [p for p in snapshot['encoders'] if p['arguments'][0].endswith('ffmpeg') and '/media/source/video.mp4' in p['arguments']]
assert len(video) == 1, 'Concurrent attachments must have exactly one actual video encoder'
PYOWNER
    checkpoint attached
    combined_phase seek-paused
    snapshot worker "$worker_name" worker-paused
    checkpoint sought-paused
    # Capture the actual encoder identity before replacing the web process.
    docker_local stop --time 35 "$web_name" >/dev/null
    docker_local rm "$web_name" >/dev/null
    start_web
    snapshot worker "$worker_name" worker-after-web-restart
    python3 - "$artifacts/worker-paused-processes.json" "$artifacts/worker-after-web-restart-processes.json" <<'PYPIDS'
import json, sys
before, after = [json.load(open(path)) for path in sys.argv[1:]]
a = {(p['pid'],p['startTicks']) for p in before['encoders'] if p['arguments'][0].endswith('ffmpeg')}
b = {(p['pid'],p['startTicks']) for p in after['encoders'] if p['arguments'][0].endswith('ffmpeg')}
assert a and a <= b, 'Replacing web stopped or replaced its independent encoder'
PYPIDS
    snapshot web "$web_name" web-restarted
    checkpoint web-restarted
    combined_phase resume
    snapshot worker "$worker_name" worker-before-retirement
    docker_local top "$worker_name" -eo pid,ppid,args > "$artifacts/worker-host-pids.txt"
    host_identities capture < "$artifacts/worker-host-pids.txt" > "$artifacts/worker-host-identities.json"
    docker_local inspect "$worker_name" > "$artifacts/worker-inspect.json"
    python3 - "$artifacts/worker-inspect.json" "$image_id" <<'PYRECIPE'
import json, sys
worker = json.load(open(sys.argv[1]))[0]
assert worker['Image'] == sys.argv[2]
assert all(m['Type'] == 'volume' and 'docker.sock' not in m['Destination'] for m in worker['Mounts'])
assert len(worker['Mounts']) == 4
assert any(m['Destination'] == '/run/baander-worker' for m in worker['Mounts'])
source = next(m for m in worker['Mounts'] if m['Destination'] == '/media/source')
assert source['RW'] is False
assert all(m['RW'] for m in worker['Mounts'] if m is not source)
assert worker['HostConfig']['Privileged'] is False
PYRECIPE
    docker_local logs "$worker_name" > "$artifacts/worker-before-retirement.log" 2>&1
fi
operator recover 0
operator recover 0
operator status 0
python3 - "$work/status.json" <<'PY'
import json, sys
with open(sys.argv[1]) as stream: result = json.load(stream)['result']
assert result['manifestMatches'] is True, result
assert all(u['startClaimed'] and u['removed'] for u in result['units'].values()) and result['retirement'] == 'completed', result
assert result['reservation'] == 'none' and result['readiness'] == 'not_checked', result
PY
# The old boot cannot acquire a second container or a new start after retirement.
operator create 3
operator start 3
for unit_name in "$control_name" "$worker_name"; do
    test -z "$(docker_local container ls --all --no-trunc --filter "name=^/$unit_name$" --format '{{.ID}}')"
done
if [ "$two_command" = 1 ]; then
    host_identities verify < "$artifacts/worker-host-identities.json" > "$artifacts/containment.json"
    snapshot web "$web_name" web-worker-contained
    checkpoint worker-contained
    combined_phase pause-absent
    boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
    worker_name="$(python3 - "$namespace" "$boot" <<'PYNAME'
import hashlib, sys
print('baander-worker-' + hashlib.sha256(sys.argv[1].encode()).hexdigest()[:32] + '-' + sys.argv[2] + '-execution')
PYNAME
)"
    control_name="${worker_name%-execution}-control"
    python3 - "$work/manifest.json" "$boot" <<'PYBOOT'
import json, sys
path, boot = sys.argv[1:]
with open(path) as stream: manifest = json.load(stream)
manifest['bootId'] = boot
with open(path, 'w') as stream: json.dump(manifest, stream)
PYBOOT
    if [ "$operator_in_container" = 1 ]; then
        docker_local exec -i "$run_id-controller" /bin/sh -c 'umask 077; cat > /tmp/operator/manifest.json' < "$work/manifest.json"
    fi
    operator create 0
    operator start 0
    await_check ready-recovered
    await_check ready-recovered "$worker_name"
    combined_phase recovered
    snapshot worker "$worker_name" worker-recovered
    snapshot web "$web_name" web-worker-recovered
    checkpoint worker-recovered
    # Capture the live encoder before cancel or dependency failure can stop it.
    docker_local top "$worker_name" -eo pid,ppid,args > "$artifacts/recovered-host-pids.txt"
    host_identities capture < "$artifacts/recovered-host-pids.txt" > "$artifacts/recovered-host-identities.json"
    combined_phase cancel
    # Queue failure must be bounded while a separate web process remains live.
    # Ephemeral Redis loses consumer groups; restarting it need not keep this
    # deployment alive. Exact-container retirement below also handles its exit.
    docker_local stop --time 5 "$run_id-redis" >/dev/null
    combined_phase dependency
    snapshot web "$web_name" web-queue-outage
    docker_local start "$run_id-redis" >/dev/null
    docker_local logs "$worker_name" > "$artifacts/worker-before-final-retirement.log" 2>&1
    operator recover 0
    host_identities verify < "$artifacts/recovered-host-identities.json" > "$artifacts/final-containment.json"
    test -z "$(docker_local container ls --all --filter "name=^/$worker_name$" --format '{{.ID}}')"
    checkpoint complete
    python3 - "$artifacts" <<'PYSUMMARY'
import json, pathlib, sys
root = pathlib.Path(sys.argv[1])
phases = []
for path in root.glob('*.json'):
    value = json.loads(path.read_text())
    if isinstance(value, dict) and 'phase' in value:
        phases.append(dict(phase=value['phase'], seconds=value['seconds']))
with (root/'summary.json').open('w') as stream:
    json.dump(dict(phases=phases, artifactCount=len(list(root.iterdir())), artifactBytes=sum(p.stat().st_size for p in root.iterdir() if p.is_file())), stream, indent=2)
PYSUMMARY
    echo 'PASS: production web and admitted worker delivered decoded media, converged attachments, persisted controls, survived web replacement, recovered after containment and retired without surviving containers.'
fi
echo 'PASS: trusted operator created and reconciled both stopped immutable units, started real application roles, processed outbox/scheduler effects, and retired the exact deployment without same-boot reuse.'
