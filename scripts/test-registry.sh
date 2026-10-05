#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail
cd "$(dirname "$0")/.."

mode="${1:-release}"
if [[ $# -gt 1 || ( "$mode" != release && "$mode" != sanitize && "$mode" != thread && "$mode" != fuzz ) ]]; then
    echo "Usage: $0 [release|sanitize|thread|fuzz]" >&2
    exit 2
fi
if [[ "$(uname -s)-$(uname -m)" != Linux-x86_64 ]]; then
    echo "The verified rqlite qualification artifact requires Linux x86_64." >&2
    exit 2
fi
for command in cmake ninja c++ make perl curl openssl python3 sha256sum pkg-config; do
    command -v "$command" >/dev/null
done

if [[ "$mode" == thread || "$mode" == fuzz ]]; then
    export CC="${CC:-clang}" CXX="${CXX:-clang++}"
    command -v "$CC" >/dev/null
    command -v "$CXX" >/dev/null
fi
if [[ "$mode" == fuzz ]]; then
    command -v base64 >/dev/null
    fuzz_runs="${REGISTRY_FUZZ_RUNS:-10000}"
    fuzz_seconds="${REGISTRY_FUZZ_SECONDS:-120}"
    if [[ ! "$fuzz_runs" =~ ^[1-9][0-9]*$ || ! "$fuzz_seconds" =~ ^[1-9][0-9]*$ ]]; then
        echo "REGISTRY_FUZZ_RUNS and REGISTRY_FUZZ_SECONDS must be positive integers." >&2
        exit 2
    fi
fi

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
if [[ "$mode" == sanitize || "$mode" == fuzz ]]; then
    options=(-DCMAKE_BUILD_TYPE=Debug -DREGISTRY_SANITIZERS=ON)
    if [[ "$mode" == fuzz ]]; then
        options+=(-DREGISTRY_FUZZ=ON)
    fi
    export ASAN_OPTIONS=detect_leaks=1:halt_on_error=1
    export UBSAN_OPTIONS=halt_on_error=1:print_stacktrace=1
elif [[ "$mode" == thread ]]; then
    options=(-DCMAKE_BUILD_TYPE=RelWithDebInfo -DREGISTRY_SANITIZERS=OFF -DREGISTRY_THREAD_SANITIZER=ON)
    export TSAN_OPTIONS=halt_on_error=1:exitcode=66
fi
PKG_CONFIG_PATH="$work/openssl/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}" \
cmake -S relay -B "$work/build" -G Ninja \
    -DOPENSSL_ROOT_DIR="$work/openssl" -DOPENSSL_USE_STATIC_LIBS=TRUE \
    "${options[@]}"
cmake --build "$work/build" --parallel 2
ctest --test-dir "$work/build" --output-on-failure --no-tests=error
export PYTHONDONTWRITEBYTECODE=1
python3 relay/tests/test_cluster_cleanup.py
python3 relay/tests/run_rqlite_contract.py \
    --rqlited "$work/rqlite/rqlited" --fixture "$work/build/registry_contract_fixture"
python3 relay/tests/run_transport_contract.py --fixture "$work/build/registry_transport_fixture"
python3 relay/tests/run_http_contract.py \
    --rqlited "$work/rqlite/rqlited" --server "$work/build/baander-registry"
python3 relay/tests/run_cluster_contract.py --nodes 3 \
    --rqlited "$work/rqlite/rqlited" --server "$work/build/baander-registry"
python3 relay/tests/run_restore_contract.py \
    --rqlited "$work/rqlite/rqlited" --server "$work/build/baander-registry"
if [[ "$mode" == fuzz ]]; then
    # Mutations stay in temporary storage; committed seeds remain reproducible.
    mkdir "$work/corpus" "$work/artifacts"
    cp relay/tests/parser_corpus/* "$work/corpus/"
    "$work/build/registry_parser_fuzz" "$work/corpus" \
        -runs="$fuzz_runs" -max_total_time="$fuzz_seconds" -seed=1 \
        -max_len=65537 -timeout=10 -rss_limit_mb=1024 \
        -artifact_prefix="$work/artifacts/" > "$work/fuzz.log" 2>&1 || {
            cat "$work/fuzz.log" >&2
            for artifact in "$work/artifacts/"*; do
                [[ -f "$artifact" ]] || continue
                echo "Failing input (base64): $(base64 -w0 "$artifact")" >&2
            done
            exit 1
        }
    tail -n 12 "$work/fuzz.log"
    # Negative control proves that the actual compiler/runtime reports memory bugs.
    cat > "$work/fuzz-negative.cpp" <<'CPP'
#include <cstddef>
#include <cstdint>
extern "C" int LLVMFuzzerTestOneInput(const std::uint8_t *, std::size_t) {
    auto *bytes = new char[1];
    delete[] bytes;
    return *static_cast<volatile char *>(bytes);
}
CPP
    "$CXX" -O0 -g -fsanitize=fuzzer,address,undefined \
        "$work/fuzz-negative.cpp" -o "$work/fuzz-negative"
    if "$work/fuzz-negative" -runs=1 -artifact_prefix="$work/artifacts/" \
        > "$work/fuzz-negative.log" 2>&1; then
        echo "Fuzzer negative control unexpectedly succeeded." >&2
        exit 1
    fi
    if ! python3 -c 'import pathlib, sys; sys.exit("AddressSanitizer: heap-use-after-free" not in pathlib.Path(sys.argv[1]).read_text())' "$work/fuzz-negative.log"; then
        cat "$work/fuzz-negative.log" >&2
        echo "Fuzzer negative control did not report the expected memory error." >&2
        exit 1
    fi
    echo "Parser fuzzer ASan negative control passed."
fi
echo "Registry $mode qualification passed."
