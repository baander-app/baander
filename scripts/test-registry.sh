#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail
cd "$(dirname "$0")/.."

mode="${1:-release}"
if [[ $# -gt 1 || ( "$mode" != release && "$mode" != sanitize ) ]]; then
    echo "Usage: $0 [release|sanitize]" >&2
    exit 2
fi
if [[ "$(uname -s)-$(uname -m)" != Linux-x86_64 ]]; then
    echo "The verified rqlite qualification artifact requires Linux x86_64." >&2
    exit 2
fi
for command in cmake ninja c++ make perl curl openssl python3 sha256sum pkg-config; do
    command -v "$command" >/dev/null
done

work="$(mktemp -d /tmp/baander-registry-ci.XXXXXXXX)"
trap 'rm -rf "$work"' EXIT
curl --fail --silent --show-error --location --retry 3 --max-time 180 \
    https://github.com/openssl/openssl/releases/download/openssl-3.5.3/openssl-3.5.3.tar.gz \
    -o "$work/openssl.tar.gz"
echo "c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf  $work/openssl.tar.gz" | sha256sum --check --status
tar -xzf "$work/openssl.tar.gz" -C "$work"
(
    cd "$work/openssl-3.5.3"
    ./Configure --prefix="$work/openssl" --libdir=lib no-shared no-tests
    make -j2
    make install_sw
) > "$work/openssl-build.log" 2>&1 || { cat "$work/openssl-build.log" >&2; exit 1; }

curl --fail --silent --show-error --location --retry 3 --max-time 180 \
    https://github.com/rqlite/rqlite/releases/download/v10.5.1/rqlite-v10.5.1-linux-amd64.tar.gz \
    -o "$work/rqlite.tar.gz"
echo "f0ebf593b573595022947add67cd22e6cbb02c1d2a1ed8c7da45c94093a49b0d  $work/rqlite.tar.gz" | sha256sum --check --status
mkdir "$work/rqlite"
tar -xzf "$work/rqlite.tar.gz" -C "$work/rqlite" --strip-components=1

options=(-DCMAKE_BUILD_TYPE=Release -DREGISTRY_SANITIZERS=OFF)
if [[ "$mode" == sanitize ]]; then
    options=(-DCMAKE_BUILD_TYPE=Debug -DREGISTRY_SANITIZERS=ON)
    export ASAN_OPTIONS=detect_leaks=1:halt_on_error=1
    export UBSAN_OPTIONS=halt_on_error=1:print_stacktrace=1
fi
PKG_CONFIG_PATH="$work/openssl/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}" \
cmake -S relay -B "$work/build" -G Ninja \
    -DOPENSSL_ROOT_DIR="$work/openssl" -DOPENSSL_USE_STATIC_LIBS=TRUE \
    "${options[@]}"
cmake --build "$work/build" --parallel 2
ctest --test-dir "$work/build" --output-on-failure --no-tests=error
export PYTHONDONTWRITEBYTECODE=1
python3 relay/tests/run_rqlite_contract.py \
    --rqlited "$work/rqlite/rqlited" --fixture "$work/build/registry_contract_fixture"
python3 relay/tests/run_transport_contract.py --fixture "$work/build/registry_transport_fixture"
python3 relay/tests/run_http_contract.py \
    --rqlited "$work/rqlite/rqlited" --server "$work/build/baander-registry"
echo "Registry $mode qualification passed."
