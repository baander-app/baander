#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_DNS_RUNTIME_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_DNS_RUNTIME_TIMEOUT_ACTIVE=1 timeout --kill-after=20s 180s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
run_id="baander-dns-$(date +%s)-$$"
cleanup() {
    local status=$?
    trap - EXIT
    if [ "$status" -ne 0 ]; then
        docker exec "$run_id" cat /tmp/baander-dns-server.log 2>/dev/null || true
    fi
    if ! timeout 15s docker rm -f "$run_id" >/dev/null 2>&1; then
        echo "DNS runtime container cleanup failed: $run_id" >&2
        status=1
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

docker run -d --name "$run_id" --privileged --network none --entrypoint sleep \
    -e XDEBUG_MODE=off \
    "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" infinity >/dev/null

project_directory=/var/www/html
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; then
    project_directory=/tmp/baander-dns-runtime
    docker exec "$run_id" mkdir -p "$project_directory"
    tar -cf - vendor src tests bin composer.json composer.lock packages |
        docker exec -i "$run_id" tar -xf - -C "$project_directory"
fi

# Only the disposable, network-isolated container's resolver is changed.
docker exec --user 0 "$run_id" sh -ec 'printf "nameserver 127.0.0.1\noptions attempts:1 timeout:1\n" > /etc/resolv.conf'
docker exec --user 0 -d -w "$project_directory" "$run_id" sh -ec \
    'exec python3 tests/Fixtures/Http/dns-server.py --ready-file /tmp/baander-dns-ready > /tmp/baander-dns-server.log 2>&1'
for attempt in $(seq 1 100); do
    if docker exec "$run_id" test -f /tmp/baander-dns-ready; then
        break
    fi
    sleep 0.05
done
docker exec "$run_id" test -f /tmp/baander-dns-ready
docker exec --user www-data -w "$project_directory" "$run_id" php tests/Fixtures/Http/dns-runtime.php
