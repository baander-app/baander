/**
 * WASM type definitions for DSP modules.
 * Source: packages/dsp/ on master branch.
 *
 * These modules are loaded from /dsp/*.js (JS glue) and /dsp/*.wasm (binaries),
 * served as static assets by Symfony (production) or Vite (development).
 */

// --- Loudness R128 ---

export interface LoudnessR128API {
  memory: WebAssembly.Memory
  init(sampleRate: number, truePeakOversample: number): void
  reset(): void
  process(inputPtr: number, frames: number, channels: number): void
  lufsM(): number
  lufsS(): number
  lufsI(): number
  lra(): number
  truePkDbfs(): number
}

export interface LoudnessModule {
  loadLoudness(url?: string): Promise<LoudnessR128API>
}

// --- Dynamics Meter ---

export interface DynamicsMeterAPI {
  memory: WebAssembly.Memory
  init(attackMs: number, releaseMs: number, sampleRate: number): void
  reset(): void
  process(inputPtr: number, frames: number, channels: number): void
  rmsL(): number
  rmsR(): number
  peakL(): number
  peakR(): number
  crestL(): number
  crestR(): number
}

export interface DynamicsModule {
  loadDynamics(url?: string): Promise<DynamicsMeterAPI>
}

// --- Spectral Features ---

export interface SpectralFeaturesApi {
  memory: WebAssembly.Memory
  malloc(n: number): number
  free(ptr: number): void
  init(fftSize: number, sampleRate: number): void
  computeFromMag(magPtr: number): void
  getCentroidHz(): number
  getRolloffHz(percentile: number): number
  getFlux(): number
  getFlatness(): number
  getPeakIndex(): number
  getBandEnergies(outPtr: number, bands: number): void
}

export interface SpectralFeaturesModule {
  loadSpectralFeatures(url?: string): Promise<SpectralFeaturesApi>
}
