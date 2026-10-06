#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Direct dependency notices are checked; this is not a transitive SBOM/license audit.
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ $# != 0 ]]; then
    echo "Usage: $0" >&2
    exit 2
fi
export CC=clang-19 CXX=clang++-19
for command in cmake ninja "$CC" "$CXX" clang-tidy-19 make perl curl python3 sha256sum pkg-config cmp; do
    command -v "$command" >/dev/null
done
python3 -c 'import yaml'
clang-tidy-19 --version | grep -Eq 'LLVM version 19\.' || {
    echo 'Registry static qualification requires clang-tidy 19.' >&2
    exit 2
}
work="$(mktemp -d /tmp/baander-registry-static.XXXXXXXX)"
trap 'rm -rf "$work"' EXIT

# Unknown FetchContent dependencies fail closed instead of silently missing a notice.
python3 - <<'PY'
import pathlib
import re

cmake = pathlib.Path('relay/CMakeLists.txt').read_text()
expected = {
    'boost_headers': (
        'https://archives.boost.io/release/1.88.0/source/boost_1_88_0.tar.bz2',
        '46d9d2c06637b219270877c9e16155cbd015b6dc84349af064c088e9b5b12f7b',
    ),
    'json': (
        'https://github.com/nlohmann/json/releases/download/v3.12.0/json.tar.xz',
        '42f6e95cad6ec532fd372391373363b62a14af6d771056dbfc86160e6dfff7aa',
    ),
    'googletest': (
        'https://codeload.github.com/google/googletest/tar.gz/refs/tags/v1.17.0',
        '65fab701d9829d38cb77c14acdc431d2108bfdbf8979e40eb8ae567edf10b27c',
    ),
}
declarations = re.findall(r'FetchContent_Declare\s*\((.*?)\)', cmake, re.S | re.I)
actual = {}
for declaration in declarations:
    name = declaration.split()[0]
    url = re.search(r'\bURL\s+(\S+)', declaration)
    digest = re.search(r'\bURL_HASH\s+SHA256=([a-f0-9]{64})', declaration)
    if name in actual or not url or not digest:
        raise SystemExit('Unreviewed dependency declaration: ' + declaration)
    actual[name] = (url[1], digest[1])
if actual != expected:
    raise SystemExit('Registry dependency inventory changed; review versions, hashes and licenses.')
if set(re.findall(r'find_package\s*\(\s*(\w+)', cmake, re.I)) != {'OpenSSL', 'Threads', 'Python3'}:
    raise SystemExit('Registry environment dependency inventory changed; review its licenses.')
if 'find_package(OpenSSL 3.5.3 EXACT REQUIRED)' not in cmake:
    raise SystemExit('Registry OpenSSL pin changed; review its source and license.')
for filename, version, digest in [
    (
        'relay/docker/Dockerfile',
        'openssl-3.5.3',
        'c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf',
    ),
    (
        'relay/docker/rqlite.Dockerfile',
        'rqlite-v10.5.1-linux-amd64',
        'f0ebf593b573595022947add67cd22e6cbb02c1d2a1ed8c7da45c94093a49b0d',
    ),
]:
    dockerfile = pathlib.Path(filename).read_text()
    if version not in dockerfile or digest not in dockerfile:
        raise SystemExit('Registry packaged dependency pin changed: ' + filename)
notices = {'boost', 'json', 'googletest', 'openssl', 'rqlite'}
if {p.name.removesuffix('-LICENSE.txt') for p in pathlib.Path('relay/third_party').glob('*-LICENSE.txt')} != notices:
    raise SystemExit('Registry retained-notice inventory changed; review its coverage.')
sources = [
    *pathlib.Path('relay/cpp').rglob('*.cpp'),
    *pathlib.Path('relay/include').rglob('*.hpp'),
    *pathlib.Path('relay/tests').rglob('*.cpp'),
]
for source in sources:
    if 'SPDX-License-Identifier: Apache-2.0' not in '\n'.join(source.read_text().splitlines()[:5]):
        raise SystemExit('Missing first-party Apache-2.0 declaration: ' + str(source))
print('Reviewed direct dependency pins and first-party license declarations passed.')
PY

curl --fail --silent --show-error --location --retry 3 --max-time 180 \
    https://github.com/openssl/openssl/releases/download/openssl-3.5.3/openssl-3.5.3.tar.gz \
    -o "$work/openssl.tar.gz"
echo "c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf  $work/openssl.tar.gz" | sha256sum --check --status
tar -xzf "$work/openssl.tar.gz" -C "$work"
cmp relay/third_party/openssl-LICENSE.txt "$work/openssl-3.5.3/LICENSE.txt"
(
    cd "$work/openssl-3.5.3"
    ./Configure --prefix="$work/openssl" --libdir=lib no-shared no-tests
    make -j2
    make install_sw
) > "$work/openssl-build.log" 2>&1 || { tail -n 80 "$work/openssl-build.log" >&2; exit 1; }
PKG_CONFIG_PATH="$work/openssl/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}" \
cmake -S relay -B "$work/build" -G Ninja \
    -DCMAKE_BUILD_TYPE=Debug -DCMAKE_EXPORT_COMPILE_COMMANDS=ON \
    -DOPENSSL_ROOT_DIR="$work/openssl" -DOPENSSL_USE_STATIC_LIBS=TRUE \
    -DREGISTRY_SANITIZERS=OFF -DREGISTRY_THREAD_SANITIZER=OFF -DREGISTRY_FUZZ=OFF
cmp relay/third_party/boost-LICENSE.txt "$work/build/_deps/boost_headers-src/LICENSE_1_0.txt"
cmp relay/third_party/json-LICENSE.txt "$work/build/_deps/json-src/LICENSE.MIT"
cmp relay/third_party/googletest-LICENSE.txt "$work/build/_deps/googletest-src/LICENSE"
# Pin the external database notice by content as well as the reviewed release tag.
curl --fail --silent --show-error --location --retry 3 --max-time 180 \
    https://raw.githubusercontent.com/rqlite/rqlite/v10.5.1/LICENSE -o "$work/rqlite-LICENSE"
echo "eebaac1ed2a0deece5e8ea39c679bd2c8813aa8da3ec95cdda953b95ce349629  $work/rqlite-LICENSE" | sha256sum --check --status
cmp relay/third_party/rqlite-LICENSE.txt "$work/rqlite-LICENSE"
echo 'All five reviewed direct dependency notices match upstream.'

checks='-*,clang-analyzer-*'
checks+=',bugprone-use-after-move,bugprone-dangling-handle,bugprone-infinite-loop'
checks+=',bugprone-misplaced-widening-cast,bugprone-sizeof-expression'
checks+=',bugprone-suspicious-memset-usage,bugprone-undefined-memory-manipulation'
# clang-tidy can retain a dependency diagnostic when its trace includes user-code
# notes (Clang 19 + Boost.Asio co_await does this). Enforce scope on the structured
# primary location, without disabling a checker or template analysis. Compiler
# errors, unknown paths, missing exports and analyzer crashes always fail.
cat > "$work/check-diagnostics.py" <<'PYDIAG'
import pathlib
import sys
import yaml

status = int(sys.argv[1])
export = pathlib.Path(sys.argv[2])
dependencies = pathlib.Path(sys.argv[3]).resolve()
if status not in (0, 1):
    raise SystemExit('clang-tidy failed without completing analysis: ' + str(status))
if not export.exists():
    if status:
        raise SystemExit('clang-tidy failed without exporting diagnostics.')
    raise SystemExit(0)
diagnostics = (yaml.safe_load(export.read_text()) or {}).get('Diagnostics', [])
if status and not diagnostics:
    raise SystemExit('clang-tidy failed without structured diagnostics.')
for diagnostic in diagnostics:
    name = diagnostic['DiagnosticName']
    filename = diagnostic['DiagnosticMessage']['FilePath']
    location = pathlib.Path(filename).resolve() if filename else None
    if (not location or not location.is_relative_to(dependencies)
            or not name.startswith(('clang-analyzer-', 'bugprone-'))):
        raise SystemExit('Blocking diagnostic: ' + name + ' at ' + filename)
    print('Outside production source scope: ' + name + ' at ' + filename)
PYDIAG
# Explicit options avoid inheriting a repository or user clang-tidy suppression file.
for source in relay/cpp/*.cpp; do
    echo "Analyzing $source"
    status=0
    rm -f "$work/diagnostics.yaml"
    clang-tidy-19 "$source" -p "$work/build" \
        --export-fixes="$work/diagnostics.yaml" \
        --config="{Checks: '$checks', WarningsAsErrors: '*', HeaderFilterRegex: '.*/relay/include/.*', SystemHeaders: false}" \
        > "$work/clang-tidy.log" 2>&1 || status=$?
    python3 "$work/check-diagnostics.py" "$status" "$work/diagnostics.yaml" "$work/build/_deps" || {
        tail -n 120 "$work/clang-tidy.log" >&2
        exit 1
    }
done
# Controls prove real bugs fail, including the same CallAndMessage checker that
# reports Boost.Asio's coroutine frame. Both primary diagnostics are first-party.
cat > "$work/analyzer-negative.cpp" <<'CPP'
struct Object { int value; int get() { return value; } };
int uninitialized_object() {
    Object *object;
    return object->get();
}
int null_pointer() {
    int *pointer = nullptr;
    return *pointer;
}
CPP
status=0
clang-tidy-19 "$work/analyzer-negative.cpp" \
    --export-fixes="$work/negative-diagnostics.yaml" \
    --config="{Checks: '$checks', WarningsAsErrors: '*'}" -- -std=c++20 \
    > "$work/analyzer-negative.log" 2>&1 || status=$?
if [[ "$status" == 0 ]] || \
    ! grep -q 'clang-analyzer-core.NullDereference' "$work/analyzer-negative.log" || \
    ! grep -q 'clang-analyzer-core.CallAndMessage' "$work/analyzer-negative.log"; then
    cat "$work/analyzer-negative.log" >&2
    echo 'Static analyzer controls did not report both expected memory bugs.' >&2
    exit 1
fi
if python3 "$work/check-diagnostics.py" "$status" "$work/negative-diagnostics.yaml" "$work/build/_deps" \
    > "$work/negative-scope.log" 2>&1; then
    echo 'First-party diagnostic scope control unexpectedly passed.' >&2
    exit 1
fi
grep -q 'Blocking diagnostic: clang-analyzer-core.' "$work/negative-scope.log" || {
    cat "$work/negative-scope.log" >&2
    echo 'First-party scope control failed without the expected diagnostic rejection.' >&2
    exit 1
}
# A real compiler failure must remain blocking even when the export also contains
# an out-of-scope dependency analyzer warning.
cat > "$work/compiler-negative.cpp" <<'CPP'
int broken() { return missing_symbol; }
CPP
status=0
clang-tidy-19 "$work/compiler-negative.cpp" \
    --export-fixes="$work/compiler-diagnostics.yaml" \
    --config="{Checks: '$checks', WarningsAsErrors: '*'}" -- -std=c++20 \
    > "$work/compiler-negative.log" 2>&1 || status=$?
python3 - "$work/compiler-diagnostics.yaml" "$work/build/_deps" <<'PYMIX'
import pathlib
import sys
import yaml

export = pathlib.Path(sys.argv[1])
data = yaml.safe_load(export.read_text())
diagnostics = data['Diagnostics']
if not any(item['DiagnosticName'] == 'clang-diagnostic-error' for item in diagnostics):
    raise SystemExit('Compiler control did not export a real compiler error.')
diagnostics.insert(0, {
    'DiagnosticName': 'clang-analyzer-core.CallAndMessage',
    'DiagnosticMessage': {
        'FilePath': str(pathlib.Path(sys.argv[2]) / 'boost_headers-src/boost/asio/impl/awaitable.hpp'),
    },
})
export.write_text(yaml.safe_dump(data))
PYMIX
if python3 "$work/check-diagnostics.py" "$status" "$work/compiler-diagnostics.yaml" "$work/build/_deps" \
    > "$work/compiler-scope.log" 2>&1; then
    echo 'Compiler diagnostic scope control unexpectedly passed.' >&2
    exit 1
fi
grep -q 'Blocking diagnostic: clang-diagnostic-error' "$work/compiler-scope.log" || {
    cat "$work/compiler-scope.log" >&2
    echo 'Compiler control failed without rejecting the real compiler error.' >&2
    exit 1
}
echo 'Registry production static analysis and direct dependency license qualification passed.'
