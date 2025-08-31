/**
 * Low-level WASM API exported by the spectral features module.
 * Maps directly to the underlying C++ exports; the JS loader resolves
 * both underscored and plain names via a pick() helper.
 */
export interface SpectralFeaturesApi {
  /**
   * WebAssembly linear memory to create typed views over.
   */
  memory: WebAssembly.Memory;

  /**
   * Allocate raw bytes on the WASM heap.
   * @param n Number of bytes to allocate.
   * @returns Byte offset (pointer) in WASM memory.
   */
  malloc(n: number): number;

  /**
   * Free a previously allocated pointer.
   * @param ptr Byte offset (pointer) returned by malloc().
   */
  free(ptr: number): void;

  /**
   * Initialize internal configuration.
   * @param fftSize Full FFT size (expects N/2 magnitude bins).
   * @param sampleRate Sampling rate in Hz.
   */
  init(fftSize: number, sampleRate: number): void;

  /**
   * Compute spectral features from a magnitude spectrum in byte form.
   * The input buffer is expected to be Uint8Array[g_bins] where g_bins = fftSize / 2.
   * Each element is in the 0..255 range and will be normalized to [0..1] internally.
   * Updates internal feature state (centroid, flux, peak index, flatness).
   *
   * @param magPtr Byte offset (pointer) to Uint8Array[g_bins] magnitude data.
   */
  computeFromMag(magPtr: number): void;

  /**
   * Last computed spectral centroid in Hz.
   */
  getCentroidHz(): number;

  /**
   * Spectral rolloff in Hz at the given percentile (0..1), computed from the last normalized spectrum.
   * Also updates the cached rolloff value internally.
   * @param percentile 0.0 .. 1.0
   * @returns Rolloff frequency in Hz.
   */
  getRolloffHz(percentile: number): number;

  /**
   * Spectral flux computed against the previous normalized spectrum.
   */
  getFlux(): number;

  /**
   * Spectral flatness (geometric mean / arithmetic mean), in [0..1].
   */
  getFlatness(): number;

  /**
   * Index of the peak magnitude bin (0..g_bins-1).
   */
  getPeakIndex(): number;

  /**
   * Compute log-spaced band energies (0..255 per band) into the provided output buffer.
   * Output must be a Uint8Array of length "bands" at outPtr.
   * @param outPtr Byte offset (pointer) to Uint8Array[bands].
   * @param bands Number of log-spaced bands to compute.
   */
  getBandEnergies(outPtr: number, bands: number): void;
}

/**
 * Load and instantiate the spectral features WebAssembly module.
 * The loader resolves both underscored and plain export names.
 * @param url Optional URL to the .wasm binary. Defaults to './spectral_features.wasm' (module-relative).
 */
export function loadSpectralFeatures(url?: string): Promise<SpectralFeaturesApi>;

