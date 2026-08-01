import wasmModulesJson from './wasm-modules.json' q;

/**
 * List of WASM DSP modules used in the application
 * Shared between Vite plugins and app code
 */
export const WASM_MODULES = wasmModulesJson as unknown as readonly [
  'spectral_features',
  'dynamics_meter',
  'fft2048',
  'loudness_r128',
  'partitioned_convolver',
  'resampler_hq',
];

export type WasmModule = typeof WASM_MODULES[number];
