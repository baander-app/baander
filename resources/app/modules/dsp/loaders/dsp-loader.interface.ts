/**
 * Platform-agnostic interface for loading DSP WASM modules
 * Each platform provides its own implementation
 */
export interface DspLoader {
  /**
   * Load a DSP module's JavaScript loader code
   * @param module - Module name (e.g., 'loudness_r128')
   * @returns Promise resolving to the module with load* functions
   */
  loadJsModule<T>(module: DspModuleType): Promise<T>;

  /**
   * Get the URL for a WASM file
   * @param module - Module name (e.g., 'loudness_r128')
   * @returns URL string appropriate for the platform
   */
  getWasmUrl(module: DspModuleType): string;
}

export type DspModuleType =
  | 'dynamics_meter'
  | 'loudness_r128'
  | 'partitioned_convolver'
  | 'resampler_hq'
  | 'spectral_features';
