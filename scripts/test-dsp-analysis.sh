#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if [[ $# -ne 0 ]]; then
    echo "Usage: $0 (requires Emscripten 6.0.3; checks tracked artifacts without changing them)" >&2
    exit 2
fi

compiler="${EMCC:-emcc}"
version="$("$compiler" --version)"
version="${version%%$'\n'*}"
if [[ "$version" != *" 6.0.3 ("* ]]; then
    echo "DSP qualification requires Emscripten 6.0.3; found: $version" >&2
    exit 1
fi

work="$(mktemp -d /tmp/baander-dsp-analysis.XXXXXXXX)"
trap 'rm -rf "$work"' EXIT
modules=(fft2048 spectral_features)

for pass in 1 2; do
    output="$work/build-$pass"
    mkdir -p "$output"
    for module in "${modules[@]}"; do
        log="$output/$module.build.log"
        if ! make -B -C "packages/dsp/$module" EMCC="$compiler" WASM="$output/$module.wasm" >"$log" 2>&1; then
            cat "$log" >&2
            exit 1
        fi
    done
    DSP_WASM_DIR="$output" node --test packages/dsp/tests/*.test.mjs
done

for module in "${modules[@]}"; do
    first="$work/build-1/$module.wasm"
    if ! cmp -s "$first" "$work/build-2/$module.wasm"; then
        echo "Non-reproducible build: $module" >&2
        exit 1
    fi
    for artifact in "packages/dsp/$module/$module.wasm" "public/dsp/$module.wasm" "ui/electron/public/dsp/$module.wasm"; do
        if ! cmp -s "$first" "$artifact"; then
            echo "Stale or missing artifact: $artifact; rebuild with Emscripten 6.0.3 and copy the result." >&2
            exit 1
        fi
    done
done

if ! cmp -s packages/dsp/fft2048/wasm-spectrum.js public/audio-worklets/wasm-spectrum.js; then
    echo "Stale or missing worklet: public/audio-worklets/wasm-spectrum.js; copy packages/dsp/fft2048/wasm-spectrum.js." >&2
    exit 1
fi

echo "DSP analysis qualification passed: reference vectors, reproducible builds, and shipped artifacts."
