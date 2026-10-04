import type { LoudnessR128API, DynamicsMeterAPI, SpectralFeaturesApi } from './wasm-types'

const DSP_BASE = '/dsp/'
type DspModuleType = 'dynamics_meter' | 'loudness_r128' | 'spectral_features'

// Compilation is immutable and shareable. Instances, memories and DSP history are not.
const compiledModules = new Map<DspModuleType, Promise<WebAssembly.Module>>()

function compileModule(name: DspModuleType): Promise<WebAssembly.Module> {
  const cached = compiledModules.get(name)
  if (cached) return cached
  const compilation = (async () => {
    const url = getWasmUrl(`${name}.wasm`)
    const response = await fetch(url)
    if (!response.ok) throw new Error(`Failed to load DSP WASM: ${url} (status: ${response.status})`)
    return WebAssembly.compile(await response.arrayBuffer())
  })()
  compiledModules.set(name, compilation)
  // A failed older request must not evict a replacement installed after reset.
  void compilation.catch(() => {
    if (compiledModules.get(name) === compilation) compiledModules.delete(name)
  })
  return compilation
}

async function instantiateModule(name: DspModuleType): Promise<WebAssembly.Exports> {
  const instance = await WebAssembly.instantiate(await compileModule(name), {})
  const exports = instance.exports
  if (exports._initialize !== undefined) {
    exportedFunction<() => void>(exports, '_initialize')()
  }
  return exports
}

function exportedFunction<T extends (...args: never[]) => unknown>(exports: WebAssembly.Exports, name: string): T {
  const value = exports[name] ?? exports[`_${name}`]
  if (typeof value !== 'function') throw new Error(`Missing required DSP WASM function: ${name}`)
  // WASM exposes function arity/types through its module ABI; names are validated here.
  return value as T
}

function exportedMemory(exports: WebAssembly.Exports): WebAssembly.Memory {
  if (!(exports.memory instanceof WebAssembly.Memory)) throw new Error('Missing required DSP WASM memory')
  return exports.memory
}

/** Each call returns a separately owned instance, including native programme history. */
export async function getLoudness(): Promise<LoudnessR128API> {
  const e = await instantiateModule('loudness_r128')
  return {
    memory: exportedMemory(e),
    malloc: exportedFunction(e, 'malloc'), free: exportedFunction(e, 'free'),
    init: exportedFunction(e, 'init_loudness'), reset: exportedFunction(e, 'reset_loudness'),
    process: exportedFunction(e, 'process_frames'),
    lufsM: exportedFunction(e, 'get_lufs_momentary'), lufsS: exportedFunction(e, 'get_lufs_shortterm'),
    lufsI: exportedFunction(e, 'get_lufs_integrated'), lra: exportedFunction(e, 'get_lra'),
    truePkDbfs: exportedFunction(e, 'get_true_peak_dbfs'),
  }
}

export async function getDynamics(): Promise<DynamicsMeterAPI> {
  const e = await instantiateModule('dynamics_meter')
  return {
    memory: exportedMemory(e),
    malloc: exportedFunction(e, 'malloc'), free: exportedFunction(e, 'free'),
    init: exportedFunction(e, 'init_meters'), reset: exportedFunction(e, 'reset_meters'),
    process: exportedFunction(e, 'process_frames'),
    rmsL: exportedFunction(e, 'get_rms_left'), rmsR: exportedFunction(e, 'get_rms_right'),
    peakL: exportedFunction(e, 'get_peak_left'), peakR: exportedFunction(e, 'get_peak_right'),
    crestL: exportedFunction(e, 'get_crest_left'), crestR: exportedFunction(e, 'get_crest_right'),
  }
}

export async function getSpectralFeatures(): Promise<SpectralFeaturesApi> {
  const e = await instantiateModule('spectral_features')
  return {
    memory: exportedMemory(e),
    malloc: exportedFunction(e, 'wasm_malloc'), free: exportedFunction(e, 'wasm_free'),
    init: exportedFunction(e, 'init_features'), computeFromMag: exportedFunction(e, 'compute_from_mag'),
    getCentroidHz: exportedFunction(e, 'get_centroid_hz'), getRolloffHz: exportedFunction(e, 'get_rolloff_hz'),
    getFlux: exportedFunction(e, 'get_flux'), getFlatness: exportedFunction(e, 'get_flatness'),
    getPeakIndex: exportedFunction(e, 'get_peak_index'), getBandEnergies: exportedFunction(e, 'get_band_energies'),
  }
}

/** Clear shared compilation work without resetting or detaching any live instance. */
export function resetDspCache(): void {
  compiledModules.clear()
}

export function getWasmUrl(filename: string): string {
  return `${DSP_BASE}${filename}`
}

export function getAudioWorkletUrl(filename: string): string {
  return `/audio-worklets/${filename}`
}
