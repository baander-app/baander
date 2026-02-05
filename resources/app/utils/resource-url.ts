import { isElectron } from './platform';

/**
 * Get the URL for a WASM file
 * In Electron (both dev and production), uses custom protocol to serve from Resources/public directory
 * In web/dev, uses regular fetch
 *
 * @param filename - The WASM filename (e.g., 'spectral_features.wasm')
 * @returns The URL to fetch the WASM file from
 */
export function getWasmUrl(filename: string): string {
  if (isElectron()) {
    // Use custom protocol in Electron
    return `baander://dsp/${filename}`;
  } else {
    // Use regular path in web/dev
    return `/dsp/${filename}`;
  }
}

/**
 * Get the URL for an audio worklet file
 * In Electron (both dev and production), uses custom protocol to serve from Resources directory
 * In web/dev, uses regular path
 *
 * @param filename - The worklet filename (e.g., 'audio-analysis-worker.js')
 * @returns The URL to load the worklet from
 */
export function getAudioWorkletUrl(filename: string): string {
  if (isElectron()) {
    // Use custom protocol in Electron
    return `baander://audio-worklets/${filename}`;
  } else {
    // Use regular path in web/dev
    return `/audio-worklets/${filename}`;
  }
}
