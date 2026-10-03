#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Exercise Docker's matcher with synthetic markers, never real checkout secrets.
work="$(mktemp -d /tmp/baander-docker-context.XXXXXXXX)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/context"
cp .dockerignore "$work/context/.dockerignore"
cat > "$work/context/Dockerfile" <<'DOCKERFILE'
FROM scratch
COPY . /context/
DOCKERFILE
python3 - "$work" <<'PY'
import json, pathlib, sys
work = pathlib.Path(sys.argv[1])
excluded = [
    '.git/sentinel', '.github/sentinel', '.claude/sentinel',
    '.agents/sentinel', '.codex/sentinel', '.gitnexus/sentinel',
    '.pi/sentinel', '.rpiv/sentinel', '.reli/sentinel',
    '.idea/sentinel', 'ui/rn/android/.idea/sentinel',
    'ui/rn/android/.claude/sentinel', '.phpunit.cache/sentinel', '.deptrac.cache',
    'node_modules/sentinel', 'ui/rn/node_modules/sentinel',
    'ui/web/node_modules/sentinel', 'packages/local/node_modules/sentinel',
    'ui/rn/.yarn/install-state.gz', 'vendor/sentinel', 'var/cache/sentinel',
    'config/secrets/sentinel', 'docker/dev/juul.localdomain.key', 'auth.json', '.env.local', '.env.prod.local', '.env.local.php',
    'ui/rn/android/build/sentinel', 'ui/rn/android/app/build/sentinel',
    'ui/rn/android/app/.cxx/sentinel', 'ui/rn/android/.gradle/sentinel',
    'ui/rn/android/.kotlin/sentinel', 'ui/rn/ios/build/sentinel',
    'ui/rn/ios/DerivedData/sentinel', 'ui/web/playwright-report/sentinel',
    'ui/web/test-results/sentinel',
]
included = [
    'src/Shared/sentinel.php', 'bin/console', 'composer.json', 'composer.lock',
    'packages/swoole-bundle/composer.json', 'packages/swoole-bundle/LICENSE',
    'packages/baander-phpstan-rules/src/sentinel.php', 'packages/tsduck-php-ffi/sentinel.php',
    'patches/sentinel.patch', 'LICENSE.md', 'public/build/sentinel.js',
    'public/assets/sentinel.css', 'public/dsp/sentinel.wasm', 'ui/web/src/sentinel.tsx',
    'ui/web/tests/e2e/__screenshots__/capture-for-inspection.spec.ts',
    'ui/web/tests/e2e/characterization/__snapshots__/sentinel.png',
    'ui/rn/android/app/build.gradle', 'ui/rn/android/app/src/main/sentinel.kt',
    'ui/rn/android/gradle/wrapper/gradle-wrapper.jar', 'ui/rn/ios/Baander/sentinel.swift',
    'ui/rn/.yarn/patches/sentinel.patch', '.env', '.env.test', 'docker/general/start-web.sh',
]
for name in excluded + included:
    path = work / 'context' / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text('synthetic context marker\n')
(work / 'expectations.json').write_text(json.dumps(dict(excluded=excluded, included=included)))
PY
docker buildx build --network=none --progress=plain --output "type=local,dest=$work/export" \
    "$work/context" > "$work/build.log" 2>&1 || { tail -40 "$work/build.log" >&2; exit 1; }
python3 - "$work" <<'PY'
import json, pathlib, sys
work = pathlib.Path(sys.argv[1])
expected = json.loads((work / 'expectations.json').read_text())
root = work / 'export' / 'context'
for name in expected['excluded']:
    assert not (root / name).exists(), f'Host artifact entered Docker context: {name}'
for name in expected['included']:
    assert (root / name).is_file(), f'Required source or asset excluded: {name}'
print(f"PASS: {len(expected['excluded'])} host artifacts excluded; {len(expected['included'])} source, asset and license markers preserved.")
PY
