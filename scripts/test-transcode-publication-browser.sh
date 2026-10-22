#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BAANDER_TEST_IMAGE:?Set BAANDER_TEST_IMAGE to the CI image with PHP, FFmpeg 5 and test dependencies.}"
artifacts="${BAANDER_TEST_ARTIFACTS:-$PWD/var/test-runs/transcode-publication-browser-$(date +%s)-$$}"
mkdir -p "$artifacts"
chmod 700 "$artifacts"
workspace="$(mktemp -d /tmp/baander-publication-browser.XXXXXXXX)"
fixture="baander-publication-fixture-$(date +%s)-$$"
browser="${fixture}-browser"
fixture_created=0
browser_created=0
cleanup() {
    local status=$? cleanup_status=0
    if [ "$browser_created" = 1 ]; then
        timeout 10s docker logs "$browser" > "$artifacts/browser.log" 2>&1 || true
        if mkdir -p "$artifacts/browser"; then
            timeout 10s docker cp "$browser:/work/evidence/." "$artifacts/browser" >/dev/null 2>&1 || cleanup_status=1
        fi
        timeout 10s docker rm -f "$browser" >/dev/null 2>&1 || cleanup_status=1
    fi
    if [ "$fixture_created" = 1 ]; then
        timeout 10s docker logs "$fixture" > "$artifacts/fixture.log" 2>&1 || true
        if mkdir -p "$artifacts/media"; then
            timeout 10s docker cp "$fixture:/tmp/baander-publication/." "$artifacts/media" >/dev/null 2>&1 || cleanup_status=1
        fi
        timeout 10s docker stop --time 5 "$fixture" >/dev/null 2>&1 || true
        timeout 10s docker rm -f "$fixture" >/dev/null 2>&1 || cleanup_status=1
    fi
    if docker container ls --all --format '{{.Names}}' > "$workspace/containers"; then
        if grep -Fxq -e "$fixture" -e "$browser" "$workspace/containers"; then cleanup_status=1; fi
    else cleanup_status=1
    fi
    printf '{"cleanupVerified":%s}\n' "$([ "$cleanup_status" = 0 ] && echo true || echo false)" > "$artifacts/cleanup.json"
    rm -rf "$workspace"
    if [ "$cleanup_status" != 0 ]; then
        echo 'Publication browser cleanup could not be verified.' >&2
        status=1
    fi
    exit "$status"
}
trap cleanup EXIT
mkdir -p "$workspace/assets" "$workspace/browser/node_modules"
node - "$workspace/assets/versions.json" <<'JS'
const { readFileSync, writeFileSync } = require('node:fs')
const dashjs = JSON.parse(readFileSync('ui/web/node_modules/dashjs/package.json')).version
const playwright = JSON.parse(readFileSync('ui/web/node_modules/playwright-core/package.json')).version
if (dashjs !== '5.2.1' || playwright !== '1.60.0') throw new Error('Install the pinned dash.js 5.2.1 and Playwright 1.60.0 dependencies.')
writeFileSync(process.argv[2], JSON.stringify({ dashjs, playwright }))
JS
cp ui/web/node_modules/dashjs/dist/modern/umd/dash.all.min.js "$workspace/assets/"
cp ui/web/tests/e2e/transcode-publication/index.html "$workspace/assets/"
cp ui/web/tests/e2e/transcode-publication/browser.mjs "$workspace/browser/"
cp -R ui/web/node_modules/playwright-core "$workspace/browser/node_modules/"
timeout 30s docker create --name "$fixture" --network none --memory=1g --pids-limit=128 --user root \
    -e BAANDER_PROGRESSIVE_DIRECTORY=/tmp/baander-publication \
    --entrypoint php "$BAANDER_TEST_IMAGE" /var/www/html/tests/Fixtures/Transcode/PublicationBrowserBootstrap.php >/dev/null
fixture_created=1
# Copy the current implementation into a disposable CI container. No running
# application, database or host media directory is mounted or modified.
timeout 30s docker cp src/. "$fixture:/var/www/html/src/"
timeout 30s docker cp tests/Fixtures/Transcode "$fixture:/var/www/html/tests/Fixtures/"
timeout 30s docker cp "$workspace/assets" "$fixture:/tmp/baander-publication-assets"
docker start "$fixture" >/dev/null
ready=0
for attempt in $(seq 1 100); do
    if timeout 2s docker exec "$fixture" test -s /tmp/baander-publication/ready.json; then ready=1; break; fi
    if [ "$(docker inspect --format '{{.State.Running}}' "$fixture")" != true ]; then break; fi
    sleep 0.2
done
if [ "$ready" != 1 ]; then echo 'The real publication fixture did not become ready.' >&2; exit 1; fi
docker cp "$fixture:/tmp/baander-publication/ready.json" "$workspace/browser/ready.json"
timeout 30s docker create --name "$browser" --network "container:$fixture" --memory=1g --pids-limit=256 \
    --entrypoint node mcr.microsoft.com/playwright:v1.60.0-noble /work/browser.mjs >/dev/null
browser_created=1
docker cp "$workspace/browser" "$browser:/work"
echo "Publication browser evidence: $artifacts"
timeout 100s docker start -a "$browser"
test "$(docker inspect --format '{{.State.ExitCode}}' "$browser")" = 0
docker cp "$browser:/work/evidence/result.json" "$artifacts/result.json"
node - "$artifacts/result.json" <<'JS'
const { readFileSync } = require('node:fs')
const result = JSON.parse(readFileSync(process.argv[2]))
if (result.passed !== true) throw new Error('The reference DASH browser did not qualify natural EOF.')
console.log(JSON.stringify({ passed: true, browser: result.browser, dashjs: result.dashjs,
  decodedFrames: result.final.decodedFrames, audioEvidence: result.audioEvidence,
  duration: result.final.duration, ended: result.final.ended }))
JS
