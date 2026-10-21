#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
phase="$1" artifacts="$2" base_url="$3"
container_file="$artifacts/browser-container"
if [ "$phase" = cleanup ]; then
    if [ -f "$container_file" ]; then
        container="$(cat "$container_file")"
        cleanup_status=0
        mkdir -p "$artifacts/browser"
        if inventory="$(docker container ls --all --format '{{.Names}}')"; then
            if grep -Fxq -- "$container" <<< "$inventory"; then
                docker cp "$container:/work/evidence/." "$artifacts/browser" >/dev/null 2>&1 || cleanup_status=1
            fi
        else
            cleanup_status=1
        fi
        docker rm -f "$container" >/dev/null 2>&1 || true
        if inventory="$(docker container ls --all --format '{{.Names}}')"; then
            if grep -Fxq -- "$container" <<< "$inventory"; then
                cleanup_status=1
            else
                rm -f "$container_file"
            fi
        else
            cleanup_status=1
        fi
        if [ "$cleanup_status" != 0 ]; then
            echo 'Browser evidence capture or container cleanup could not be verified.' >&2
        fi
        exit "$cleanup_status"
    fi
    exit 0
fi
if [ "$phase" = authenticated ]; then
    printf '%s\n' 'WARNING: Playback tests can produce audible tones or media through your speakers/headphones. Lower or mute system output before running; tests may change player volume.' >&2
    test ! -e "$container_file"
    container="baander-two-command-browser-$(date +%s)-$$"
    printf '%s\n' "$container" > "$container_file"
    docker create --name "$container" --network host --memory=2g --pids-limit=256 \
        --entrypoint node mcr.microsoft.com/playwright:v1.60.0-noble /work/browser.mjs >/dev/null
    workspace="$(mktemp -d /tmp/baander-browser-checkpoint.XXXXXXXX)"
    mkdir -p "$workspace/node_modules"
    cp -R ui/web/node_modules/playwright-core "$workspace/node_modules/playwright-core"
    cp ui/web/tests/e2e/two-command/browser.mjs "$workspace/browser.mjs"
    docker cp "$workspace" "$container:/work"
    rm -rf "$workspace"
    docker start "$container" >/dev/null
    for attempt in $(seq 1 100); do
        if docker exec "$container" test -s /work/browser-port; then break; fi
        sleep 0.1
    done
fi
container="$(cat "$container_file")"
python3 - "$phase" "$artifacts/browser.json" "$base_url" <<'PY' |
import json, pathlib, sys
print(json.dumps({'phase': sys.argv[1], 'credentials': json.loads(pathlib.Path(sys.argv[2]).read_text()), 'baseUrl': sys.argv[3]}))
PY
    docker exec -i "$container" node --input-type=module -e '
import { readFile } from "node:fs/promises";
let body = ""; for await (const chunk of process.stdin) body += chunk;
const phase = JSON.parse(body).phase;
const phaseTimeout = phase === "client-controls" ? 360000 : 90000;
const port = await readFile("/work/browser-port", "utf8");
const response = await fetch(`http://127.0.0.1:${port}`, {method:"POST", body, signal:AbortSignal.timeout(phaseTimeout)});
console.log(await response.text());
if (!response.ok) process.exitCode = 1;
'
