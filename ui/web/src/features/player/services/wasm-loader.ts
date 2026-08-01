import type {
  LoudnessR128API,
  DynamicsMeterAPI,
  SpectralFeaturesApi,
} from './wasm-types'

const DSP_BASE = '/dsp/'

type DspModuleType = 'dynamics_meter' | 'loudness_r128' | 'spectral_features'

interface DspModule {
  name: DspModuleType
  loadMethod: 'loadDynamics' | 'loadLoudness' | 'loadSpectralFeatures'
}

const modules: DspModule[] = [
  { name: 'dynamics_meter', loadMethod: 'loadDynamics' },
  { name: 'loudness_r128', loadMethod: 'loadLoudness' },
  { name: 'spectral_features', loadMethod: 'loadSpectralFeatures' },
]

/**
 * Load a DSP module's JS glue file, which internally fetches and instantiates the WASM binary.
 * The glue files use `fetch('./module.wasm')` with a relative URL, so we need to load them
 * via blob URL to control the base path (matching master's WebDspLoader approach).
 */
async function loadJsModule<T>(module: DspModuleType): Promise<T> {
  const url = `${DSP_BASE}${module}.js`
  const response = await fetch(url)

  if (!response.ok) {
    throw new Error(`Failed to load DSP JS module: ${url} (status: ${response.status})`)
  }

  const code = await response.text()
  const blob = new Blob([code], { type: 'application/javascript' })
  const blobUrl = URL.createObjectURL(blob)

  try {
    const mod = await import(/* @vite-ignore */ blobUrl)
    return mod as T
  } finally {
    URL.revokeObjectURL(blobUrl)
  }
}

/**
 * Load a specific DSP module by name, caching the result.
 */
async function loadModule(module: DspModule): Promise<unknown> {
  const mod = await loadJsModule<Record<string, (url: string) => Promise<unknown>>>(module.name)
  const wasmUrl = `${DSP_BASE}${module.name}.wasm`
  return mod[module.loadMethod](wasmUrl)
}

// --- Cached getters ---

let loudnessPromise: Promise<LoudnessR128API> | undefined
let dynamicsPromise: Promise<DynamicsMeterAPI> | undefined
let spectralPromise: Promise<SpectralFeaturesApi> | undefined

export function getLoudness(): Promise<LoudnessR128API> {
  return loudnessPromise ??= loadModule(modules[1]) as Promise<LoudnessR128API>
}

export function getDynamics(): Promise<DynamicsMeterAPI> {
  return dynamicsPromise ??= loadModule(modules[0]) as Promise<DynamicsMeterAPI>
}

export function getSpectralFeatures(): Promise<SpectralFeaturesApi> {
  return spectralPromise ??= loadModule(modules[2]) as Promise<SpectralFeaturesApi>
}

/** Clear the module cache (useful for testing). */
export function resetDspCache(): void {
  loudnessPromise = undefined
  dynamicsPromise = undefined
  spectralPromise = undefined
}

/** Get the URL for a WASM binary. */
export function getWasmUrl(filename: string): string {
  return `${DSP_BASE}${filename}`
}

/** Get the URL for an audio worklet file. */
export function getAudioWorkletUrl(filename: string): string {
  return `/audio-worklets/${filename}`
}
