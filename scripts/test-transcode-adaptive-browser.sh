#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BAANDER_TEST_IMAGE:?Set BAANDER_TEST_IMAGE to the CI image with PHP, FFmpeg 5 and test dependencies.}"
audio_profile="${BAANDER_TEST_AUDIO_PROFILE:-streaming_stereo}"
case "$audio_profile" in
    streaming_stereo|opus_stereo) ;;
    *) echo 'BAANDER_TEST_AUDIO_PROFILE must be streaming_stereo or opus_stereo.' >&2; exit 1 ;;
esac
scenario="${BAANDER_TEST_VIDEO_SCENARIO:-adaptive}"
case "$scenario" in
    adaptive) browser_script=browser.mjs; browser_timeout=195 ;;
    sparse-fresh|sparse-seek|matroska-delayed-fresh|matroska-delayed-seek)
        if [ "$audio_profile" != streaming_stereo ]; then
            echo 'Sparse movie scenarios require streaming_stereo.' >&2; exit 1
        fi
        browser_script=sparse-browser.mjs; browser_timeout=135 ;;
    *) echo 'BAANDER_TEST_VIDEO_SCENARIO must be adaptive, sparse-fresh, sparse-seek, matroska-delayed-fresh or matroska-delayed-seek.' >&2; exit 1 ;;
esac
artifacts="${BAANDER_TEST_ARTIFACTS:-$PWD/var/test-runs/transcode-adaptive-browser-$(date +%s)-$$}"
mkdir -p "$artifacts"
chmod 700 "$artifacts"
workspace="$(mktemp -d /tmp/baander-adaptive-browser.XXXXXXXX)"
fixture="baander-adaptive-fixture-$(date +%s)-$$"
browser="${fixture}-browser"
fixture_created=0
browser_created=0
cleanup() {
    local status=$? cleanup_status=0
    trap - EXIT INT TERM
    if [ "$browser_created" = 1 ]; then
        timeout 10s docker logs "$browser" > "$artifacts/browser.log" 2>&1 || true
        if mkdir -p "$artifacts/browser"; then
            timeout 15s docker cp "$browser:/work/evidence/." "$artifacts/browser" >/dev/null 2>&1 || cleanup_status=1
        else cleanup_status=1
        fi
        timeout 10s docker rm -f "$browser" >/dev/null 2>&1 || cleanup_status=1
    fi
    if [ "$fixture_created" = 1 ]; then
        timeout 10s docker stop --time 5 "$fixture" >/dev/null 2>&1 || true
        timeout 10s docker logs "$fixture" > "$artifacts/fixture.log" 2>&1 || true
        if mkdir -p "$artifacts/media"; then
            timeout 20s docker cp "$fixture:/tmp/baander-adaptive/." "$artifacts/media" >/dev/null 2>&1 || cleanup_status=1
        else cleanup_status=1
        fi
        timeout 10s docker rm -f "$fixture" >/dev/null 2>&1 || cleanup_status=1
    fi
    if docker container ls --all --format '{{.Names}}' > "$workspace/containers"; then
        if grep -Fxq -e "$fixture" -e "$browser" "$workspace/containers"; then cleanup_status=1; fi
    else cleanup_status=1
    fi
    printf '{"cleanupVerified":%s}\n' "$([ "$cleanup_status" = 0 ] && echo true || echo false)" > "$artifacts/cleanup.json"
    rm -rf "$workspace"
    if [ "$cleanup_status" != 0 ]; then
        echo 'Adaptive browser cleanup could not be verified.' >&2
        status=1
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -p "$workspace/assets" "$workspace/browser/node_modules" "$workspace/media"
node --input-type=module - "$workspace" <<'JS'
import { readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { build } from './ui/web/node_modules/esbuild/lib/main.js'
const workspace = process.argv[2]
const hls = JSON.parse(readFileSync('ui/web/node_modules/hls.js/package.json')).version
const playwright = JSON.parse(readFileSync('ui/web/node_modules/playwright-core/package.json')).version
if (hls !== '1.7.3' || playwright !== '1.60.0') throw new Error('Install the pinned HLS.js 1.7.3 and Playwright 1.60.0 dependencies.')
const versions = { hls, playwright, sourceRevision: execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim() }
writeFileSync(`${workspace}/browser/versions.json`, JSON.stringify(versions))
const bundle = await build({ entryPoints: ['ui/web/tests/e2e/transcode-adaptive/entry.ts'], bundle: true,
  platform: 'browser', format: 'esm', target: 'chrome148', outfile: `${workspace}/assets/entry.js`, metafile: true,
  // Resolve web dependencies independently of the root workspace's PnP manifest.
  alias: { 'hls.js': resolve('ui/web/node_modules/hls.js/dist/hls.mjs') } })
if (!Object.keys(bundle.metafile.inputs).some(path => path.endsWith('/hls.js/dist/hls.mjs'))
  || Object.values(bundle.metafile.outputs).some(output => output.imports.some(imported => imported.external))) {
  throw new Error('The fixture must bundle the installed HLS.js implementation without external imports.')
}
writeFileSync(`${workspace}/browser/bundle-inputs.json`, JSON.stringify(bundle.metafile.inputs, null, 2))
JS
cp ui/web/tests/e2e/transcode-adaptive/index.html "$workspace/assets/"
cp "ui/web/tests/e2e/transcode-adaptive/$browser_script" "$workspace/browser/browser.mjs"
cp -R ui/web/node_modules/playwright-core "$workspace/browser/node_modules/"
cp "$workspace/browser/versions.json" "$artifacts/versions.json"
cp "$workspace/browser/bundle-inputs.json" "$artifacts/bundle-inputs.json"
timeout 30s docker create --name "$fixture" --network none --memory=2g --pids-limit=128 --user root \
    -e BAANDER_ADAPTIVE_DIRECTORY=/tmp/baander-adaptive \
    -e BAANDER_ADAPTIVE_AUDIO_PROFILE="$audio_profile" \
    -e BAANDER_ADAPTIVE_SCENARIO="$scenario" \
    --entrypoint php "$BAANDER_TEST_IMAGE" /var/www/html/tests/Fixtures/Transcode/AdaptiveBrowserBootstrap.php >/dev/null
fixture_created=1
# Current source and fixture assets are copied into disposable containers.
# No application service, credentials, database or original media are mounted.
timeout 30s docker cp src/. "$fixture:/var/www/html/src/"
timeout 30s docker cp tests/Fixtures/Transcode "$fixture:/var/www/html/tests/Fixtures/"
timeout 30s docker cp "$workspace/assets" "$fixture:/tmp/baander-adaptive-assets"
timeout 30s docker cp "$workspace/media" "$fixture:/tmp/baander-adaptive"
docker start "$fixture" >/dev/null
ready=0
ready_deadline=$((SECONDS + 90))
while [ "$SECONDS" -lt "$ready_deadline" ]; do
    if timeout 2s docker exec "$fixture" test -s /tmp/baander-adaptive/ready.json; then ready=1; break; fi
    if [ "$(docker inspect --format '{{.State.Running}}' "$fixture")" != true ]; then break; fi
    sleep 0.2
done
if [ "$ready" != 1 ]; then echo 'The real adaptive media fixture did not become ready.' >&2; exit 1; fi
docker cp "$fixture:/tmp/baander-adaptive/ready.json" "$workspace/browser/ready.json"
timeout 30s docker create --name "$browser" --network "container:$fixture" --memory=1g --pids-limit=256 \
    --entrypoint node mcr.microsoft.com/playwright:v1.60.0-noble /work/browser.mjs >/dev/null
browser_created=1
docker cp "$workspace/browser" "$browser:/work"
echo "Adaptive browser evidence: $artifacts"
timeout "${browser_timeout}s" docker start -a "$browser"
test "$(docker inspect --format '{{.State.ExitCode}}' "$browser")" = 0
docker cp "$browser:/work/evidence/result.json" "$artifacts/result.json"
node - "$artifacts/result.json" <<'JS'
const { readFileSync } = require('node:fs')
const result = JSON.parse(readFileSync(process.argv[2]))
if (result.passed !== true) throw new Error('Movie playback did not qualify.')
console.log(JSON.stringify({ passed: true, scenario: result.scenario ?? 'adaptive', browser: result.browser,
  hls: result.hls, sourceRevision: result.sourceRevision }))
JS
