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
modules=(fft2048 spectral_features dynamics_meter loudness_r128)

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
    # Exercise the exact gating accumulator with a small pool so exhaustion is
    # tested without synthesizing hours of audio. This binary is never shipped.
    if ! "$compiler" packages/dsp/tests/gating/harness.cpp \
        -O3 -fno-math-errno -msimd128 -s STANDALONE_WASM=1 --no-entry \
        -s ENVIRONMENT=web -s STRICT=1 -s INITIAL_MEMORY=33554432 \
        -s ALLOW_MEMORY_GROWTH=0 \
        -s 'EXPORTED_FUNCTIONS=["_malloc","_free","_init_loudness","_reset_loudness","_process_frames","_get_lufs_momentary","_get_lufs_shortterm","_get_lufs_integrated","_get_lra","_get_true_peak_dbfs","_gate_add","_gate_sum","_gate_count","_gate_used","_gate_exhausted","_gate_valid","_gate_node_bytes"]' \
        -lc++ -lc++abi -o "$output/loudness_gating_harness.wasm" >"$output/gating.build.log" 2>&1; then
        cat "$output/gating.build.log" >&2
        exit 1
    fi
    DSP_WASM_DIR="$output" node --test packages/dsp/tests/*.test.mjs packages/dsp/tests/gating/*.test.mjs
done

if ! cmp -s "$work/build-1/loudness_gating_harness.wasm" "$work/build-2/loudness_gating_harness.wasm"; then
    echo "Non-reproducible gating test build" >&2
    exit 1
fi

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

for artifact in public/dsp/dynamics_meter.js ui/electron/public/dsp/dynamics_meter.js; do
    if ! cmp -s packages/dsp/dynamics_meter/dynamics_meter.js "$artifact"; then
        echo "Stale or missing wrapper: $artifact; copy packages/dsp/dynamics_meter/dynamics_meter.js." >&2
        exit 1
    fi
done

if ! cmp -s packages/dsp/fft2048/wasm-spectrum.js public/audio-worklets/wasm-spectrum.js; then
    echo "Stale or missing worklet: public/audio-worklets/wasm-spectrum.js; copy packages/dsp/fft2048/wasm-spectrum.js." >&2
    exit 1
fi

echo "DSP analysis qualification passed: reference vectors, reproducible builds, and shipped artifacts."
