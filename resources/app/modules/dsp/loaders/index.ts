import { DspLoader } from './dsp-loader.interface';
import { UniversalDspLoader } from './universal-dsp-loader';

// Singleton instance
let loader: UniversalDspLoader | null = null;

/**
 * Get the universal DSP loader (works for both Web and Electron)
 */
export function resolveDspLoader(): DspLoader {
  if (!loader) {
    loader = new UniversalDspLoader();
  }
  return loader;
}

// Export for testing
export function resetLoader(): void {
  loader = null;
}
