#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
startup="$PWD/docker/general/start-web.sh"
test -f "$startup"
probe_root=$(mktemp -d "${TMPDIR:-/tmp}/baander-startup.XXXXXX")
probe_pid=
watchdog_pid=
active_case=
cleanup() {
    if [ -n "$watchdog_pid" ]; then
        kill -TERM "$watchdog_pid" 2>/dev/null || true
        wait "$watchdog_pid" 2>/dev/null || true
    fi
    if [ -n "$active_case" ] && [ -f "$active_case/pid" ]; then
        kill -KILL "$(cat "$active_case/pid")" 2>/dev/null || true
    fi
    if [ -n "$probe_pid" ]; then
        kill -TERM "$probe_pid" 2>/dev/null || true
        wait "$probe_pid" 2>/dev/null || true
    fi
    /bin/rm -rf "$probe_root"
}
trap cleanup EXIT
mkdir -p "$probe_root/bin"

# Intercept the fixed container cache path, mapping it to disposable test data.
# No production path is created, mounted, read, or removed by this probe.
cat > "$probe_root/bin/rm" <<'SH'
#!/bin/bash
set -euo pipefail
if [ "$#" -ne 2 ] || [ "$1" != -rf ] || [ "$2" != /var/www/html/var/cache/dev ]; then
    echo "Unexpected startup cleanup command" >&2
    exit 91
fi
printf 'cleanup\n' >> "$PROBE_CASE/events"
/bin/rm -rf "$PROBE_CASE/cache/dev"
SH
cat > "$probe_root/bin/php" <<'SH'
#!/bin/bash
set -euo pipefail
printf '%s\0' "$@" > "$PROBE_CASE/arguments"
printf '%s\n' "$$" > "$PROBE_CASE/pid"
if [ -e "$PROBE_CASE/cache/dev/marker" ]; then
    printf 'cache-present\n' >> "$PROBE_CASE/events"
else
    printf 'cache-absent\n' >> "$PROBE_CASE/events"
fi
if [ "$PROBE_MODE" = exit ]; then exit 47; fi
trap 'printf "TERM\n" > "$PROBE_CASE/signal"; exit 73' TERM
touch "$PROBE_CASE/ready"
while :; do sleep 0.05; done
SH
chmod +x "$probe_root/bin/rm" "$probe_root/bin/php"

extra_arguments=(--host 'value with spaces' '' $'line\nbreak' '*literal*')
printf '%s\0' bin/console app:serve --no-interaction "${extra_arguments[@]}" > "$probe_root/expected-arguments"

run_case() {
    local environment=$1 mode=$2 status=0
    local case_directory="$probe_root/$environment $mode"
    active_case=$case_directory
    mkdir -p "$case_directory/cache/dev" "$case_directory/cache/prod"
    touch "$case_directory/cache/dev/marker" "$case_directory/cache/prod/marker"
    local -a launch=(env -u APP_ENV "PATH=$probe_root/bin:$PATH"
        "PROBE_CASE=$case_directory" "PROBE_MODE=$mode")
    if [ "$environment" != unset ]; then
        launch+=("APP_ENV=$environment")
    fi
    "${launch[@]}" /bin/sh "$startup" "${extra_arguments[@]}" &
    probe_pid=$!
    (
        sleep 5 &
        timer_pid=$!
        trap 'kill "$timer_pid" 2>/dev/null || true' EXIT
        trap 'exit 0' TERM
        wait "$timer_pid"
        touch "$case_directory/timed-out"
        echo "Startup probe timed out: $environment $mode" >&2
        if [ -f "$case_directory/pid" ]; then
            kill -KILL "$(cat "$case_directory/pid")" 2>/dev/null || true
        fi
        kill -KILL "$probe_pid" 2>/dev/null || true
    ) &
    watchdog_pid=$!
    if [ "$mode" = signal ]; then
        local attempt
        for attempt in $(seq 1 100); do
            if [ -f "$case_directory/ready" ]; then break; fi
            if ! kill -0 "$probe_pid" 2>/dev/null; then
                echo "Startup exited before the PHP probe became ready" >&2
                return 1
            fi
            sleep 0.05
        done
        test -f "$case_directory/ready"
        # exec must preserve the PID given to the container entrypoint.
        test "$(cat "$case_directory/pid")" = "$probe_pid"
        kill -TERM "$probe_pid"
    fi
    wait "$probe_pid" || status=$?
    kill -TERM "$watchdog_pid" 2>/dev/null || true
    wait "$watchdog_pid" 2>/dev/null || true
    watchdog_pid=
    local launched_pid=$probe_pid
    probe_pid=
    test ! -e "$case_directory/timed-out"
    test "$(cat "$case_directory/pid")" = "$launched_pid"
    cmp "$probe_root/expected-arguments" "$case_directory/arguments"
    test -f "$case_directory/cache/prod/marker"
    if [ "$environment" = dev ]; then
        test ! -e "$case_directory/cache/dev"
        printf 'cleanup\ncache-absent\n' > "$case_directory/expected-events"
    else
        test -f "$case_directory/cache/dev/marker"
        printf 'cache-present\n' > "$case_directory/expected-events"
    fi
    cmp "$case_directory/expected-events" "$case_directory/events"
    if [ "$mode" = signal ]; then
        test "$status" -eq 73
        test "$(cat "$case_directory/signal")" = TERM
    else
        test "$status" -eq 47
    fi
    active_case=
}

for environment in unset prod dev test ''; do
    run_case "$environment" exit
done
run_case prod signal
run_case dev signal
echo "Web startup preserves arguments, exec PID, signals and exit status; only dev cache is cleared."
